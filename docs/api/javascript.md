# JavaScript API

Attach the `rl/api` library to make `Drupal.rl` available. It is a thin
transport proxy that coalesces every decide, impression, and conversion
fired on the page into a single batched POST to `rl.php`, so N
experiments on the same page produce one or two requests instead of one
set per experiment.

## Methods

### `Drupal.rl.decide(experimentId, armIds)`

Ask for a decision when the variant needs to be chosen client-side.
The arm list **must** be read from the DOM; see the discipline section below.

```javascript
var container = document.querySelector('[data-rl-experiment="hero_cta"]');
var armIds = container.dataset.rlArms.split(',');
Drupal.rl.decide('hero_cta', armIds).then(function (armId) {
  showVariant(armId);
});
```

### `Drupal.rl.rank(experimentId, armIds)`

Ask for a full ranking when you need to sort, not just pick a winner.
Use case: reordering accordion items, FAQ lists, or any sortable
content by visitor engagement.

```javascript
var items = document.querySelector('[data-rl-experiment="faq_sort"]');
var faqArms = items.dataset.rlArms.split(',');
Drupal.rl.rank('faq_sort', faqArms).then(function (sorted) {
  // sorted = ['t3', 't0', 't1', 't2'], all arms, best first
  sorted.forEach(function (armId) {
    items.appendChild(items.querySelector('[data-rl-arm="' + armId + '"]'));
  });
});
```

### `Drupal.rl.turn(experimentId, armId)`

Record an impression when the variant becomes visible.

```javascript
Drupal.rl.turn('hero_cta', 'v0');
```

### `Drupal.rl.reward(experimentId, armId)`

Record a conversion when the user clicks, submits, or converts.

```javascript
Drupal.rl.reward('hero_cta', 'v0');
```

### `Drupal.rl.flush()`

Force an immediate flush of the event queue.

```javascript
Drupal.rl.flush();
```

## Batching behaviour

Events accumulate for 500 ms and then flush in one POST. Decide,
turn, and reward events share the same queue and the same request, so
a page with a DXPR Builder variant block plus tracking on other
elements ends up making a single round trip. Buffered tracking events
are also flushed via `navigator.sendBeacon` on `visibilitychange` and
`pagehide` so they survive navigation.

## Discipline for `decide()` and `rank()`

> **Never hardcode arm IDs in JS. Always read them from a DOM
> attribute that the server-side renderer emitted.**

The DOM is downstream of the same server-render pipeline
that produced the decide's context. When the experiment manager adds
or removes a variant, the consumer's page cache is invalidated, the
next render emits the new attribute, and JS picks it up. JS never
asserts what the arm set is; it just echoes whatever the current
cached HTML says. This keeps `Drupal.rl.decide()` and
`Drupal.rl.rank()` drift-free without requiring the RL core to store
arm lists.

The convention your builder uses internally (numeric `v0..vN`, UUIDs,
node IDs, anything matching `^[a-zA-Z0-9_-]+$`) is whatever you emit
into the attribute. The RL core is arm-agnostic.

If the server returns no decision for an experiment (not registered,
no data, network error), the returned promise resolves to `armIds[0]`
so callers never need a `.catch()` for the common path.

`Drupal.rl` is one transport among several. Modules that already ship
their own tracking JS (like `rl_sorting`, which batches turns on its
own 100 ms window and posts them as form data) keep working untouched.
