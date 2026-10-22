# Dima token cost report

Date: 2026-10-19

Paid OpenAI calls in this run: **none**. Accumulated cost: **$0.00**.

The $0.50 cap was not raised. Stage B is prepared and is not started.

## Model and prices

Dima calls `config('openai.mansour_chat_model')`. The default in `config/openai.php` is `gpt-6-astra`. The local `.env` does not override it.

`DimaPrompt::complete()` still sends `max_completion_tokens` 1400 and `reasoning_effort` high. Those two settings were not changed. The production system prompt text was not changed.

Prices are the standard short-context rates for `gpt-6-astra` on the OpenAI pricing page, read on 2026-10-19:

https://developers.openai.com/api/docs/pricing

| Meter | USD per 1M tokens |
| --- | ---: |
| Input | 10.00 |
| Cached input | 1.00 |
| Cache writes | 12.50 |
| Output | 50.00 |

These prompts are about 3K tokens, under the 272K long-context tier.

Token counts use the local `o200k_base` tokenizer. That is an estimate, not a GPT-6 bill. The ceiling for one HTTP call is measured input tokens at $10 / 1M plus 1,400 output tokens at $50 / 1M. A repair is a second HTTP call and is checked the same way before it is sent.

## Free tests after the bug fixes

179 tests, 1,359 assertions, all passing. Zero HTTP calls.

The fixes, with the production prompt left as it was:

- The string `"false"` stays false. `"true"`, `"0"`, and real booleans are parsed the same way, including `close` and stored identity flags.
- `تفضل` and the other consent words return `ناوي تصوت بالانتخابات الجاي؟` without a model call.
- A failed model on `وليش منفصلين؟` returns the 2021 split answer. A failed model on `922` says there is no confirmed amount. A failed model on `خلي علي` still pulls back. A second stop and a religious objection still get their short lines.
- A failed model on a flat `لا` still marks `video: true`, and the early return keeps identity, stage, and memory.

## Stage B — four single requests, not run

Each row is one live request. History is inside that request. `هلا` and `تفضل` are not extra paid calls. `تفضل` no longer calls the model at all.

Ceiling is the 1,400-token cap. The repair column is the second call production makes only if the first reply fails the local checks. Both were measured from the production prompt and the production repair prompt.

| Test | Question | First input | First ceiling | Repair input | Repair ceiling | Pair ceiling |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| B1 | Burst ending in `شو طلعوا مع بعض؟` | 3,345 | $0.104 | 897 | $0.079 | $0.183 |
| B2 | `يعني كل المصاري انصرفت؟` | 3,113 | $0.101 | 665 | $0.077 | $0.178 |
| B3 | `شو عملوا لمكافحة الجريمة؟` | 3,249 | $0.103 | 814 | $0.078 | $0.181 |
| B4 | `شو عملوا مع بعض؟` after a split and a comparison | 3,344 | $0.103 | 909 | $0.079 | $0.183 |
| **Four first calls** | | **13,051** | **$0.411** | | | |
| **Four calls, each retried** | | | | | | **$0.724** |

The four first calls fit in $0.50, with about $0.09 left. That remainder can pay for one repair at the cap. It cannot pay for a repair on every test. If every reply failed and used the full 1,400 tokens, the run would stop during B3, before B4.

The runner stops before any HTTP call, including a repair, whose ceiling is larger than the money left. No call is sent until you approve the four.

## Are high reasoning and 1,400 tokens necessary?

Not for the length of the reply. A WhatsApp answer here is one to four sentences plus a small JSON object (`reply`, `stance`, `note`, `close`, `memory`). That visible JSON is on the order of 150–250 tokens. The facts for the turn are already in the packet.

`max_completion_tokens: 1400` is a safety cap, not a target length. At $50 / 1M, the cap alone is **$0.070** of the about **$0.103** first-call ceiling. The visible reply, if it stayed near 250 tokens, would cost about **$0.0125**.

`reasoning_effort: high` is a quality setting. On this model, reasoning tokens are billed as output and can use most of the 1,400 before the two sentences appear. The dialect and the JSON shape do not require that effort. The packet already holds the sourced facts.

Savings if the settings were lowered later, using the same measured inputs. These settings were not changed.

| Setting | Output assumed | Four first calls | Four calls plus four repairs |
| --- | ---: | ---: | ---: |
| Current cap, worst case | 1,400 | $0.411 | $0.724 |
| Cap lowered to 400 | 400 | $0.211 | $0.364 |
| High effort actually uses about 800 | 800 | $0.291 | $0.504 |
| Low effort, short JSON | 250 | $0.181 | $0.294 |

Dropping the cap to 400 while leaving reasoning on high can come back empty: the reasoning can spend the whole cap before the JSON. Lowering the effort is the saving that still leaves room for the answer. That should wait until a paid sample shows whether a lower effort still passes the local checks.

## Paid request log

| Test ID | Model | Input tokens | Cached input | Output tokens | Cost | Accumulated |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| — | — | — | — | — | — | $0.00 |

No paid request was sent.
