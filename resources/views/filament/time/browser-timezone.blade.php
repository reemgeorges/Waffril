{{-- WAFFRLI (2026-11-17): the admin's browser timezone (IANA, e.g. Asia/Damascus)
     for display (App\Support\Time\AdminTimezone). Written to the waffrli_tz
     cookie on every panel page (login included), so the next request and
     every Livewire update render times in this zone. If this page was
     rendered with another zone (first visit, zone changed, DST zone moved),
     it reloads once per zone per tab session; never in a loop. --}}
<script>
    (() => {
        try {
            const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (! zone) { return; }
            const rendered = @js(\App\Support\Time\AdminTimezone::current());
            const secure = location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = 'waffrli_tz=' + encodeURIComponent(zone) + '; path=/; max-age=31536000; SameSite=Lax' + secure;
            if (zone !== rendered) {
                const key = 'waffrli_tz_applied:' + zone;
                if (! sessionStorage.getItem(key)) {
                    sessionStorage.setItem(key, '1');
                    location.reload();
                }
            }
        } catch (e) {
            // No Intl / storage: the documented fallback zone is used.
        }
    })();
</script>
