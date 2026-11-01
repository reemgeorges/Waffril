<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * WAFFRLI (2026-11-04) — admin avatars rendered locally as an inline SVG of
 * the initials in brand colours. Replaces Filament's default provider,
 * which loads https://ui-avatars.com and so sends every admin's name to a
 * third party (and shows a broken image offline).
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim((string) Filament::getNameForDefaultAvatar($record))) ?: [])
            ->map(fn (string $part) => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1))
            ->filter()->take(2)->implode('');
        $initials = htmlspecialchars(mb_strtoupper($initials !== '' ? $initials : '·'), ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="'.Brand::PRIMARY[700].'"/>'
            .'<circle cx="32" cy="32" r="30.5" fill="none" stroke="'.Brand::GOLD.'" stroke-width="2"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="IBM Plex Sans Arabic, Arial, sans-serif" font-size="24" font-weight="700" fill="'.Brand::IVORY.'">'.$initials.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
