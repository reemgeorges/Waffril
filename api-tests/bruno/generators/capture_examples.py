"""Capture OpenAPI request/response examples from Bruno CLI JSON reports.

    bru run --env Local --reporter-json /tmp/customer.json   (in each collection)
    python3 api-tests/bruno/generators/capture_examples.py /tmp/customer.json /tmp/driver.json /tmp/admin.json

Writes docs/waffrli/openapi/examples.json, which `php artisan waffrli:openapi`
merges into the OpenAPI document. Every example is a REAL exchange with the
API (demo data only). Secrets are redacted: tokens, passwords, OTP codes and
socket signatures never reach the file. Long lists are cut to 2 items.
"""
import json, os, re, sys
from urllib.parse import urlsplit, parse_qsl

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..'))
INVENTORY = os.path.join(ROOT, 'docs', 'waffrli', 'api-inventory.md')
OUT = os.path.join(ROOT, 'docs', 'waffrli', 'openapi', 'examples.json')
SECRET_KEYS = re.compile(r'(^|_)(token|password|secret|auth|signature|code)$|^password_confirmation$|^current_password$', re.I)
KEEP_KEYS = {'order_number', 'code_hint', 'currency_code', 'country_code', 'channel_name', 'socket_id', 'token_type'}
REDACT = '<redacted>'


def route_patterns():
    routes = []
    for line in open(INVENTORY, encoding='utf-8'):
        m = re.match(r'^\| (GET|POST|PUT|PATCH|DELETE) \| `([^`]+)` \|', line.strip())
        if m:
            regex = '^' + re.sub(r'\\\{[^/]+?\\\}', '[^/]+', re.escape(m.group(2))) + '$'
            routes.append((m.group(1), m.group(2), re.compile(regex)))
    return routes


MASKED = {'phone': '+12025550101', 'email': 'admin@example.test'}


def clean(value, key='', depth=0):
    if isinstance(value, dict):
        out = {}
        for k, v in value.items():
            if k == 'code' and isinstance(v, (str, int)) and re.fullmatch(r'\d{4,8}', str(v)):
                out[k] = '123456'  # an OTP: never the real (even consumed) code
            elif k == 'code':
                out[k] = clean(v, k, depth + 1)  # e.g. a currency code
            elif SECRET_KEYS.search(k) and k not in KEEP_KEYS and v not in (None, '', []) and not isinstance(v, (dict, list, bool)):
                out[k] = REDACT
            else:
                out[k] = clean(v, k, depth + 1)
        return out
    if isinstance(value, list):
        return [clean(v, key, depth + 1) for v in value[:2]]
    if isinstance(value, str):
        if value == '********':  # Bruno masks process.env values in its report
            return MASKED.get(key, '<from .env>')
        if re.fullmatch(r'\d+\|[A-Za-z0-9]{20,}', value) or re.fullmatch(r'bruno-fake-fcm-[0-9a-f]+(-rotated)?', value):
            return REDACT
        value = re.sub(r'https?://localhost:\d+', 'http://localhost:8080', value)
        return value[:300]
    return value


def main(reports):
    routes = route_patterns()
    examples = {}
    for report in reports:
        data = json.load(open(report, encoding='utf-8'))
        data = data[0] if isinstance(data, list) else data
        for r in data['results']:
            rel = r['test']['filename']
            path = urlsplit(r['request']['url']).path
            method = r['request']['method'].upper()
            matches = [route for m, route, rx in routes if m == method and rx.match(path)]
            if not matches:
                continue
            route = matches[0]
            status = str(r['response'].get('status'))
            body = r['response'].get('data')
            if not isinstance(body, dict) or not status.isdigit():
                continue
            entry = examples.setdefault(f'{method} {route}', {'responses': {}})
            if status in entry['responses']:
                continue
            entry['responses'][status] = {'summary': r.get('name') or os.path.splitext(os.path.basename(rel))[0], 'value': clean(body)}
            if status.startswith('2') and 'request' not in entry:
                raw = r['request'].get('data')
                try:
                    req = json.loads(raw) if isinstance(raw, str) and raw.strip().startswith('{') else None
                except ValueError:
                    req = None
                query = dict(parse_qsl(urlsplit(r['request']['url']).query))
                entry['request'] = clean(req) if isinstance(req, dict) else None
                entry['query'] = clean(query) if query else None
    ordered = {k: examples[k] for k in sorted(examples)}
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8') as handle:
        json.dump(ordered, handle, ensure_ascii=False, indent=1, sort_keys=False)
        handle.write('\n')
    print(f'Wrote {os.path.relpath(OUT, ROOT)} ({len(ordered)} operations)')


if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    main(sys.argv[1:])
