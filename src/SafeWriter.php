<?php

declare(strict_types=1);

namespace EntityGenerator;

/** Remplacements atomiques fichier par fichier, journal et restauration du lot en cas d'exception. */
final class SafeWriter
{
    public function write(string $directory, array $files, array $context, bool $dryRun = false, bool $force = false): array
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new \RuntimeException('Répertoire de sortie vide.');
        }
        for ($path = $directory; $path !== dirname($path); $path = dirname($path)) {
            if (is_link($path)) {
                throw new \RuntimeException('Sortie via lien symbolique refusée : '.$path);
            }
        }
        foreach ($files as $name => $contents) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.php$/D', $name)) {
                throw new \RuntimeException('Nom de fichier invalide.');
            }
            token_get_all($contents, TOKEN_PARSE);
        }
        if ($dryRun) {
            return $this->plan($directory, $files, $force)[0];
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer la sortie.');
        }
        $lockPath = $directory.'/.entity-generator.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('Verrou symbolique refusé.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Impossible d’ouvrir le verrou de génération.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Une autre génération utilise cette sortie.');
        }
        try {
            [$report, $manifest, $expected] = $this->plan($directory, $files, $force);
            if (in_array('ignoré (conflit manuel)', $report, true)) {
                throw new \RuntimeException("Conflit manuel : aucun fichier du lot n'a été modifié. Utiliser --dry-run pour le détail, puis --force pour remplacer avec sauvegarde.");
            }
            $next = $manifest;
            $next['version'] = 1;
            $next['context'] = $context;
            foreach ($files as $name => $content) {
                $next['files'][$name] = ['writtenHash' => hash('sha256', $content), 'context' => $context];
            }
            ksort($next['files']);
            $changes = [];
            foreach ($report as $name => $status) {
                if ($status !== 'inchangé') {
                    $changes[$name] = $files[$name];
                }
            }
            $manifestContent = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if ($manifestContent !== (is_file($directory.'/.entity-generator.json') ? file_get_contents($directory.'/.entity-generator.json') : null)) {
                $changes['.entity-generator.json'] = $manifestContent;
            }
            if ($changes) {
                $this->commit($directory, $changes, $expected);
            }

            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function plan(string $directory, array $files, bool $force): array
    {
        $manifestPath = $directory.'/.entity-generator.json';
        if (file_exists($directory.'/.entity-generator-transaction.json')) {
            throw new \RuntimeException('Transaction interrompue détectée. Consulter .entity-generator-transaction.json et les sauvegardes avant de relancer (README).');
        }
        if (is_link($manifestPath)) {
            throw new \RuntimeException('Manifeste symbolique refusé.');
        }
        $manifest = ['version' => 1, 'files' => []];
        if (file_exists($manifestPath)) {
            $manifest = json_decode($this->read($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || !is_array($manifest['files'] ?? null)) {
                throw new \RuntimeException('Manifeste invalide ou version inconnue.');
            }
        }
        $report = [];
        $expected = ['.entity-generator.json' => is_file($manifestPath) ? hash('sha256', $this->read($manifestPath)) : null];
        foreach ($files as $name => $content) {
            $path = $directory.'/'.$name;
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new \RuntimeException('Cible non régulière refusée : '.$name);
            }
            if (!file_exists($path)) {
                $expected[$name] = null;
                $report[$name] = 'créé';
                continue;
            }
            $actual = hash('sha256', $this->read($path));
            $expected[$name] = $actual;
            if ($actual === hash('sha256', $content)) {
                $report[$name] = 'inchangé';
            } elseif (!$force && $actual !== ($manifest['files'][$name]['writtenHash'] ?? null)) {
                $report[$name] = 'ignoré (conflit manuel)';
            } else {
                $report[$name] = $force && $actual !== ($manifest['files'][$name]['writtenHash'] ?? null) ? 'modifié (forcé, sauvegardé)' : 'modifié';
            }
        }

        return [$report, $manifest, $expected];
    }

    private function commit(string $directory, array $changes, array $expected): void
    {
        $backupRoot = $directory.'/.entity-generator-backups';
        if (is_link($backupRoot)) {
            throw new \RuntimeException('Répertoire de sauvegarde symbolique refusé.');
        }
        $backup = $backupRoot.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6));
        if (!mkdir($backup, 0775, true)) {
            throw new \RuntimeException('Impossible de créer les sauvegardes.');
        }
        $originals = [];
        $staged = [];
        $applied = [];
        $journal = $directory.'/.entity-generator-transaction.json';
        try {
            foreach ($changes as $name => $contents) {
                $path = $directory.'/'.$name;
                $originals[$name] = is_file($path) ? $this->read($path) : null;
                $actual = $originals[$name] === null ? null : hash('sha256', $originals[$name]);
                if ($actual !== $expected[$name]) {
                    throw new \RuntimeException('Fichier changé pendant la préparation : '.$name);
                }
                if ($originals[$name] !== null) {
                    $this->put($backup.'/'.$name, $originals[$name]);
                }
                $tmp = tempnam($directory, '.entity-stage-');
                if ($tmp === false) {
                    throw new \RuntimeException('Impossible de préparer un fichier temporaire.');
                }
                $staged[$name] = $tmp;
                $this->put($tmp, $contents);
            }
            $this->put($journal, json_encode(['backup' => $backup, 'existed' => array_map(static fn ($s) => $s !== null, $originals)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ($staged as $name => $tmp) {
                $target = $directory.'/'.$name;
                $actual = is_file($target) ? hash('sha256', $this->read($target)) : null;
                if ($actual !== $expected[$name] || is_link($target)) {
                    throw new \RuntimeException('Fichier changé pendant la préparation : '.$name);
                }
                if (!rename($tmp, $directory.'/'.$name)) {
                    throw new \RuntimeException('Remplacement impossible : '.$name);
                }
                $applied[] = $name;
            }
            if (!unlink($journal)) {
                throw new \RuntimeException('Impossible de finaliser le journal.');
            }
        } catch (\Throwable $error) {
            $failed = [];
            foreach (array_reverse($applied) as $name) {
                try {
                    $path = $directory.'/'.$name;
                    if ($originals[$name] === null) {
                        if (is_file($path) && !unlink($path)) {
                            throw new \RuntimeException('Suppression du fichier nouvellement créé impossible.');
                        }
                    } else {
                        $tmp = tempnam($directory, '.entity-restore-');
                        if ($tmp === false) {
                            throw new \RuntimeException('Restauration impossible.');
                        }
                        $this->put($tmp, $originals[$name]);
                        if (!rename($tmp, $path)) {
                            throw new \RuntimeException('Restauration impossible.');
                        }
                    }
                } catch (\Throwable) {
                    $failed[] = $name;
                }
            }
            if ($failed) {
                throw new \RuntimeException('Restauration incomplète : '.implode(', ', $failed).'. Sauvegardes : '.$backup, previous: $error);
            }
            if (is_file($journal)) {
                unlink($journal);
            }
            throw new \RuntimeException('Génération annulée et fichiers restaurés. Sauvegardes : '.$backup, previous: $error);
        } finally {
            foreach ($staged as $tmp) {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
        }
    }

    private function read(string $path): string
    {
        $s = file_get_contents($path);
        if ($s === false) {
            throw new \RuntimeException('Lecture impossible : '.basename($path));
        }
        return $s;
    }

    private function put(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new \RuntimeException('Écriture incomplète : '.basename($path));
        }
    }
}
