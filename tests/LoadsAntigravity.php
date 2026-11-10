<?php

declare(strict_types=1);

namespace PigAntigravity\Test;

/**
 * The Antigravity provider on pig's registry, for a test that does not go through
 * `ExtensionLoader`. The package's classes are autoloaded by `tests/bootstrap.php`, as the
 * extension's entry autoloads them when pig loads it.
 */
trait LoadsAntigravity
{
    /**
     * What `extensions/pig-antigravity.php` does on load, minus the sign-in and the catalogue: the
     * fallback models on the registry under `Api::Extension`, with this protocol behind them, and
     * the image models under `ImageApi::Extension` with `ImageGenerator` behind them.
     */
    private static function registerAntigravity(?\PigAntigravity\AntigravityApi $api = null, ?\PigAntigravity\ImageGenerator $images = null): void
    {
        \Pig\Ai\Extension\ProviderRegistry::register(new \Pig\Ai\Extension\Provider(
            id: \PigAntigravity\Models::PROVIDER,
            name: 'Antigravity (Gemini 3, Claude, GPT-OSS)',
            models: \PigAntigravity\Models::fallback(),
            api: $api ?? new \PigAntigravity\AntigravityApi(),
            resold: true,
            images: \PigAntigravity\ImageGenerator::models(),
            imageApi: $images ?? new \PigAntigravity\ImageGenerator(),
        ));
    }
}
