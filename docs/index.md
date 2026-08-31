# RL: A/B & Multivariate Testing for Drupal

A/B and multivariate testing for Drupal using reinforcement learning. Each page
view is a trial, each conversion is a reward, and the algorithm continuously
shifts traffic to whichever variant is winning. RLHF-style feedback loop, no
fixed horizons, no third-party SaaS.

## What you can A/B test

- **[RL: A/B Test Views Content](https://www.drupal.org/project/rl_sorting)**
  (`rl_sorting`): the order of items in any Drupal View
- **RL: A/B Test Page Titles** (`rl_page_title`, bundled submodule):
  page titles for nodes, View pages, and any controller
- **RL: A/B Test Menu Links** (`rl_menu_link`, bundled submodule):
  labels in any menu link
- **DXPR Builder** integration: variant slots inside builder blocks

## Features

- **Multivariate by default**: 2 to thousands of variants, no manual configuration
- **Real-time RLHF loop**: visitor clicks update the model on every page
- **Fast HTTP REST API**: optimised JSON endpoint at `rl.php`
- **Admin reports**: per-experiment performance, traffic, and confidence at
  `/admin/reports/rl`
- **Service-based architecture**: extensible decorators, custom variant selectors
- **Data sovereignty**: no cloud, no SaaS, all data stays in your Drupal database
- **GDPR-friendly tracking**: only anonymous interaction counts, no user IDs or
  cookies

## Why RL instead of fixed-horizon A/B testing?

Traditional A/B tests run for a fixed window (say two weeks) and split traffic
50/50 the whole time, even when one variant is obviously losing. RL turns the
experiment into a feedback loop: every click adjusts the model, traffic shifts
toward the leader as soon as evidence emerges, and the test never has to "end".
You can run dozens or thousands of variants simultaneously (true multivariate
testing), and a newly added variant is in play on the next render with no
manual setup.

## How it works

RL uses a multi-armed bandit (Thompson Sampling). Each variant has a reward
distribution; on each render the algorithm samples from the distributions and
picks the highest sample. Wins update the distribution toward higher rewards;
losses update toward lower. Algorithm details:
[ThompsonCalculator.php](https://git.drupalcode.org/project/rl/-/blob/1.x/src/Service/ThompsonCalculator.php).

## Use cases

- **A/B test any content variation** without third-party SaaS
- **Multivariate test** dozens or thousands of variants at once
- **Continuous optimisation**: tests never end, the model keeps learning
- **Recommendations**: rank items by real engagement
- **Smart sorting**: reorder lists, accordions, or FAQs by visitor engagement
- **Feature flags**: route users to variants based on observed reward, not coin flip

## Related modules

- [RL: A/B Test Views Content](https://www.drupal.org/project/rl_sorting) (`rl_sorting`):
  A/B test the order of any Drupal View
- [Analyze](https://www.drupal.org/project/analyze): content analysis and
  quality scoring for Drupal
- [AI Content Strategy](https://www.drupal.org/project/ai_content_strategy):
  AI-driven content strategy recommendations for Drupal

## Resources

- [Multi-Armed Bandit Problem](https://en.wikipedia.org/wiki/Multi-armed_bandit) (Wikipedia)
- [Thompson Sampling Paper](https://www.jstor.org/stable/2332286) (original research)
- [Finite-time Analysis](https://homes.di.unimi.it/~cesa-bianchi/Pubblicazioni/ml-02.pdf) (mathematical foundations)
