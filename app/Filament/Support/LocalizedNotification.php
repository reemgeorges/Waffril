<?php

namespace App\Filament\Support;

use App\Services\Notifications\AdminAlerts;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * WAFFRLI (2026-11-11) — admin alerts are rendered in the CURRENT panel
 * language, not the language they were stored in.
 *
 * Filament rebuilds every database notification with Notification::fromArray()
 * and lets the container substitute a subclass (bound in AdminPanelProvider).
 * For a known WAFFRLI alert (viewData.waffrli_alert, see AdminAlerts::ALERTS)
 * the title, body and "Open" action are re-translated from translation keys:
 *
 *  - new alerts carry their parameters (viewData.waffrli_params);
 *  - older alerts only have the stored sentence: the parameter is recovered by
 *    matching it against that type's own template in every supported locale
 *    ("Order :reference was placed." → reference). If nothing matches exactly,
 *    the stored body is kept as it is (never guessed).
 *
 * The order reference is wrapped in bidi isolates so "WF261110-8RSIEC" keeps
 * its left-to-right order inside an Arabic sentence; it is never translated.
 * Any other notification (toasts, unknown types) is left untouched.
 */
class LocalizedNotification extends Notification
{
    /** Locales the alert templates exist in (lang/{ar,en}/admin.php → alerts). */
    public const LOCALES = ['ar', 'en'];

    public static function fromArray(array $data): static
    {
        $static = parent::fromArray($data);

        $type = $data['viewData']['waffrli_alert'] ?? null;
        if (! is_string($type) || ! isset(AdminAlerts::ALERTS[$type])) {
            return $static;
        }

        $key = AdminAlerts::translationKey($type);
        $params = $data['viewData']['waffrli_params'] ?? self::legacyParams($key, (string) ($data['body'] ?? ''));

        // The title of a known type has no parameters: always translatable.
        $static->title(__($key.'.title'));
        if (is_array($params) && isset($params['reference'])) {
            $static->body(__($key.'.body', ['reference' => self::isolate((string) $params['reference'])]));
        }

        foreach ($static->getActions() as $action) {
            if ($action instanceof Action && $action->getName() === 'open') {
                $action->label(__('admin.alerts.open'));
                // Stored links are absolute (APP_URL at event time); keep the
                // path so "Open" works on whatever host serves the panel.
                $url = $action->getUrl();
                if (is_string($url) && preg_match('#^https?://#i', $url)) {
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    $query = parse_url($url, PHP_URL_QUERY);
                    $action->url(($path !== '' ? $path : '/').($query ? '?'.$query : ''));
                }
            }
        }

        return $static;
    }

    /**
     * The parameters of an alert stored before waffrli_params existed, read
     * back from its sentence in any supported locale; null when the sentence
     * does not match a template exactly.
     *
     * @return array{reference: string}|null
     */
    public static function legacyParams(string $key, string $body): ?array
    {
        $body = trim(str_replace(["\u{2068}", "\u{2069}"], '', $body));
        if ($body === '') {
            return null;
        }
        foreach (self::LOCALES as $locale) {
            $template = __($key.'.body', [], $locale);
            if (! is_string($template) || ! str_contains($template, ':reference')) {
                continue;
            }
            $pattern = '/^'.str_replace(preg_quote(':reference', '/'), '(?<reference>\S+)', preg_quote($template, '/')).'$/u';
            if (preg_match($pattern, $body, $m)) {
                return ['reference' => $m['reference']];
            }
        }

        return null;
    }

    /** First-strong isolate: an LTR reference stays intact in RTL text. */
    public static function isolate(string $text): string
    {
        return "\u{2068}".$text."\u{2069}";
    }
}
