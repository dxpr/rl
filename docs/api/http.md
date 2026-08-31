# HTTP API (rl.php)

`rl.php` is the low-level endpoint. It is reachable directly from any
client that can make an HTTP POST: browser pages, native mobile apps,
server-side workers, other CMSes, edge functions. Deciding is *not*
exposed here; it happens in PHP at render time as described in the
[PHP API](php.md).

## Supported actions

All actions are additive; adding `batch` did not deprecate the legacy
form actions, and `rl_sorting` and other production consumers keep
using them unchanged.

| Action | Encoding | Purpose |
| --- | --- | --- |
| `ping` | form POST | Liveness check. Returns `pong`. |
| `turn` | form POST | Record one impression. |
| `turns` | form POST | Record impressions for many arms in one experiment. |
| `reward` | form POST | Record one conversion. |
| `batch` | JSON POST | Record turns and rewards across many experiments in one request. Used by `Drupal.rl`. |

Experiment IDs and arm IDs must match `^[a-zA-Z0-9_-]+$`. Experiments
must already be registered via `ExperimentRegistryInterface::register()`;
unknown IDs are silently dropped so garbage writes cannot create
registry entries.

## Legacy form actions

```bash
# Turn
curl -X POST https://example.com/modules/contrib/rl/rl.php \
  -d 'action=turn&experiment_id=hero_cta&arm_id=v0'

# Multiple arms in one experiment
curl -X POST https://example.com/modules/contrib/rl/rl.php \
  -d 'action=turns&experiment_id=hero_cta&arm_ids=v0,v1'

# Reward
curl -X POST https://example.com/modules/contrib/rl/rl.php \
  -d 'action=reward&experiment_id=hero_cta&arm_id=v0'
```

## Batch action

```
POST /modules/contrib/rl/rl.php?action=batch
Content-Type: application/json

{
  "decides": [
    {"id": "hero_cta", "arms": ["v0", "v1", "v2"]},
    {"id": "faq_sort", "arms": ["t0", "t1", "t2", "t3"], "rank": true}
  ],
  "turns": [
    {"id": "hero_cta", "arm": "v0"},
    {"id": "menu_main_5", "arm": "v1"}
  ],
  "rewards": [
    {"id": "hero_cta", "arm": "v0"}
  ]
}
```

All three sections are optional. Invalid or unregistered entries are
dropped silently so one bad event does not poison the rest of the
batch. The response is:

```json
{
  "ok": true,
  "decisions": {
    "hero_cta": {"armId": "v1"},
    "faq_sort": {"armId": "t2", "ranking": ["t2", "t0", "t3", "t1"]}
  }
}
```

`decisions` contains only entries that had a successful Thompson
Sampling lookup. Missing keys mean "use the default variant". When
`"rank": true` is set on a decide entry, the response includes a
`ranking` array with all arm IDs sorted by Thompson Sampling score
(best first). The `armId` field is always present and equals
`ranking[0]` for backwards compatibility. Turns and rewards are
fire-and-forget writes with no per-event response.

## Error responses

| Status | When |
| --- | --- |
| `400` | Missing/invalid `action`, malformed JSON, or missing `experiment_id` on a legacy action. |
| `500` | Drupal kernel failed to boot. Error logged to the PHP error log. |

## Performance notes

`rl.php` bootstraps a minimal Drupal kernel per request (same pattern as
core's `statistics.php`), not the full stack that would run behind a
normal route. One kernel boot processes the whole batch, so the cheapest
way to use this endpoint is to send as many events as possible in one
request. `Drupal.rl` already does this on the browser side; non-browser
callers should coalesce events similarly when they can.
