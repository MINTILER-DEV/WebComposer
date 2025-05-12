<?php

class WebComposer
{
    private $requires = [];
    private $installed = [];

    public function require(string $package, string $constraint = 'dev-master'): void
    {
        $this->requires[$package] = $constraint;
    }

    public function install(): void
    {
        $this->resolveDependencies();
        $this->generateAutoloader();
    }

    private function resolveDependencies(): void
    {
        $queue = new \SplQueue();
        foreach ($this->requires as $package => $constraint) {
            $queue->enqueue(['name' => $package, 'constraint' => $constraint]);
        }

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            
            if (isset($this->installed[$current['name']])) {
                if (!Semver::satisfies($this->installed[$current['name']]['version'], $current['constraint'])) {
                    throw new \RuntimeException("Version conflict for {$current['name']}");
                }
                continue;
            }

            $versionData = PackageResolver::resolve($current['name'], $current['constraint']);
            PackageResolver::install($current['name'], $versionData);
            
            $this->installed[$current['name']] = [
                'version' => $versionData['version'],
                'path' => "vendor/{$current['name']}"
            ];

            foreach ($versionData['require'] ?? [] as $dep => $depConstraint) {
                if ($dep === 'php') continue;
                $queue->enqueue(['name' => $dep, 'constraint' => $depConstraint]);
            }
        }
    }

    private function generateAutoloader(): void
    {
        foreach ($this->installed as $package) {
            AutoloadGenerator::addPackage($package['path']);
        }
        AutoloadGenerator::generate();
    }
}