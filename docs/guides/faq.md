# FAQ

## Does RL store my A/B test's variants?

**It stores their performance data, not the authoritative list.** Every variant
that has received traffic has a row in `rl_arm_data` with turn and reward
counts. But "which variants are in play right now" is owned by your module,
not RL.

Different consumer modules keep the live variant list in different places:

- **rl_sorting**: the content returned by a View
- **rl_page_title**: fields on a content entity
- **rl_menu_link**: labels on a menu link
- **DXPR Builder**: slots inside a block component

On each call your module passes its current list
(`getThompsonScores($id, NULL, $arms)` in PHP or
`Drupal.rl.decide(id, arms)` in JS) and RL matches it against the stored
stats to pick a winner. A newly added variant is in play on the next render;
a removed one stops appearing. No second save step can drift out of sync
with your module's UI.

## When do I pick a winner and end an A/B test?

**Only when you want to.** RL has no fixed horizon and no significance gate
to wait out. It just shifts traffic to whatever variant is winning right now
and keeps adapting as evidence changes.

Two patterns, depending on what you are testing:

- **Converging tests**: a better page title, a clearer checkout button, a
  stronger hero image. Once the report shows a confident winner, lock it in
  and move on.
- **Evergreen experiments**: blog post lists, banner ads that fade as
  returning visitors tune them out, seasonal calls to action. Leave them
  running. RL follows the winner as it shifts.

In both cases the loser of a pair receives progressively less traffic as
the winner's distribution strengthens, but it never drops to zero: Thompson
Sampling preserves exploration, so a losing arm always retains a small
chance of selection. Traffic only truly stops when the experiment owner
removes the variant. If you are used to fixed-horizon A/B tools,
this is the biggest mental shift: there is no "test complete" flag to chase.

## How many variants can I run at once?

There is no hard limit. Thompson Sampling scales to thousands of arms per
experiment. The practical limit is how many variants you can create and
maintain. Two variants is a classic A/B test; dozens or hundreds is true
multivariate testing.

## Does RL work with page caching?

Yes. For server-side decisions, the cache lifetime determines how often
the algorithm re-evaluates. For client-side decisions (using `Drupal.rl`),
the page can be fully cached and the variant swap happens in JavaScript
after load. See the [cache management guide](cache.md) for details.

## Does RL set cookies or track users?

No. RL tracks only anonymous interaction counts (impressions and
conversions). It does not set cookies, store user IDs, or create visitor
profiles. This makes it GDPR-friendly by design.
