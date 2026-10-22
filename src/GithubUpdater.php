<?php

namespace SimpleMail;

/**
 * Vérifie les releases GitHub du plugin et branche WordPress sur elles :
 * "Mises à jour" natif, bouton "Mettre à jour maintenant", mise à jour auto (si activée).
 *
 * Fonctionnement :
 *  - Interroge l'API GitHub "releases/latest" du dépôt (cache 6h en transient).
 *  - Compare le tag de la release (sans le "v") à SIMPLEMAIL_VERSION.
 *  - Si plus récent, propose comme "package" l'asset .zip attaché à la release
 *    (voir .github/workflows/release.yml, qui construit ce zip avec vendor/ inclus).
 *  - Corrige le nom du dossier extrait pour qu'il corresponde au slug du plugin.
 */
final class GithubUpdater
{
    private const CACHE_KEY = 'simplemail_github_release';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const ASSET_NAME = 'simplemail.zip';

    private string $repo;
    private string $pluginFile;
    private string $pluginSlug;

    public function __construct(string $pluginMainFile, string $repo)
    {
        $this->repo = $repo;
        $this->pluginFile = plugin_basename($pluginMainFile);
        $this->pluginSlug = dirname($this->pluginFile);
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginsApi'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fixSourceDir'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clearCache'], 10, 0);
        add_action('load-plugins.php', [$this, 'clearCache']);
    }

    /**
     * @return array{version:string,url:string,body:string,package:string}|null
     */
    private function fetchRelease(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(
            sprintf('https://api.github.com/repos/%s/releases/latest', $this->repo),
            [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'SimpleMail-WP-Plugin',
                ],
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // On évite de marteler l'API GitHub en cas d'échec : on cache un résultat vide un court instant.
            set_transient(self::CACHE_KEY, ['version' => '0', 'url' => '', 'body' => '', 'package' => ''], 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        $release = [
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'url' => $data['html_url'] ?? ('https://github.com/' . $this->repo),
            'body' => (string) ($data['body'] ?? ''),
            'package' => '',
        ];

        foreach ($data['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === self::ASSET_NAME) {
                $release['package'] = $asset['browser_download_url'];
                break;
            }
        }

        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    public function injectUpdate($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = $this->fetchRelease();
        if ($release === null || $release['package'] === '' || $release['version'] === '0') {
            return $transient;
        }

        if (!version_compare($release['version'], SIMPLEMAIL_VERSION, '>')) {
            return $transient;
        }

        $item = (object) [
            'id' => 'github.com/' . $this->repo,
            'slug' => $this->pluginSlug,
            'plugin' => $this->pluginFile,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'tested' => get_bloginfo('version'),
            'requires' => '5.7',
            'requires_php' => '8.1',
            'icons' => [],
            'banners' => [],
        ];

        $transient->response[$this->pluginFile] = $item;
        unset($transient->no_update[$this->pluginFile]);

        return $transient;
    }

    /**
     * @param false|object $result
     * @param mixed $args
     * @return false|object
     */
    public function pluginsApi($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->pluginSlug) {
            return $result;
        }

        $release = $this->fetchRelease();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => 'SimpleMail',
            'slug' => $this->pluginSlug,
            'version' => $release['version'],
            'author' => '<a href="https://github.com/' . $this->repo . '">Jean Roussie</a>',
            'homepage' => $release['url'],
            'sections' => [
                'description' => 'Envoie les e-mails WordPress via un serveur SMTP externe (Symfony Mailer).',
                'changelog' => $release['body'] !== '' ? wpautop(esc_html($release['body'])) : 'Voir la page de release sur GitHub.',
            ],
            'download_link' => $release['package'],
        ];
    }

    /**
     * Renomme le dossier extrait (ex. "SimpleMail-1.2.0") en "simplemail" pour que
     * WordPress remplace bien le bon répertoire de plugin.
     *
     * @return string|\WP_Error
     */
    public function fixSourceDir(string $source, string $remoteSource, $upgrader, $hookExtra = [])
    {
        if (empty($hookExtra['plugin']) || $hookExtra['plugin'] !== $this->pluginFile) {
            return $source;
        }

        global $wp_filesystem;

        if (!$wp_filesystem || trailingslashit(basename($source)) === trailingslashit($this->pluginSlug)) {
            return $source;
        }

        $desired = trailingslashit(dirname($source)) . $this->pluginSlug . '/';

        if ($wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }

        return $source;
    }

    public function clearCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }
}
