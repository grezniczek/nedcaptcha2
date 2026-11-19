# Testing and validation

## Running the regression suite

Run these commands from the repository root.

Install JavaScript test dependencies with `npm ci`, then run `npm test`. PHP with GD, PNG, and FreeType support and Node.js 18 or newer are needed for the full suite; Node.js and the npm packages are not runtime dependencies of the external module. The image-rendering tests require working GD, while separate checks disable image creation to verify the module's text-math fallback.

The suite covers editor saves and notices, in-memory survey-hook requests, invalid-configuration recovery and editor repair, and text-math fallback with image creation disabled, including a final fallback when random-number generation is disabled. Invalid-configuration checks verify that incorrect answers remain blocked, correct math answers unlock the survey, and explicit disabled mode remains available. Pagination regressions cover discovery in the first and later sections with pagination enabled or disabled, helper fields across sections on single-page surveys, separate/shared CAPTCHA sections, Next/Previous, validation redisplay, Save & Return from either page, return-code continuation, optional response capture, repeating instances, and capture failures.

Editor load checks cover design-rights and draft-mode failures, rejected requests, malformed/legacy replies, text-safe messages, duplicate opens during a pending load, closing and reopening after a failure, and editing valid settings accompanied by warnings.

Run `php tests/rendering.php` separately to verify image generation with every PHP diagnostic, including precision-loss deprecations, treated as an error. These checks decode the generated PNGs and verify nonblank content, dimensions, unchanged challenge types, and reused answers. They cover simple/complex math images and distorted images at every allowed length, angle variation, size variation, and noise density.

Submission recovery checks cover normal submissions and Save & Return after proof expiry, session loss, or missing log state on single-page and paginated surveys, with response capture enabled or disabled. They also cover checkbox controls, blank/zero/multiline values, native upload and validation context, incorrect answers, browser/tab isolation, proof rotation, buffer expiration and consumption, unsafe control filtering, math challenges, retry before saving, and capture failures. Eight additional upload-recovery cases cover owned and lost native upload contexts with both submission actions and pagination settings, including checkbox prefill and preserving the verified CAPTCHA response until the replacement upload is saved.

To exercise the pagination cases with a local REDCap installation's actual `Survey::getPageFields()` and `Survey::setPageNum()` methods, run `REDCAP_SURVEY_CLASS=/path/to/redcap/Classes/Survey.php php tests/pagination.php`. Database access and record saves remain in-memory doubles; these checks do not execute REDCap's full browser, validation, or persistence pipeline.

The automated suite also covers public survey links in multiple arms, Stop Action recovery with save and discard settings, malformed color and custom-pair configuration, editing structured pairs, repairing truncated annotations, and adding the first editor link without reloading.

## November 17 browser validation (1.1.4 development build)

The issues identified in the code review on 2026-11-16 have been corrected and covered by automated checks on PHP 8.4.22, including pagination checks using the local REDCap survey class. Browser validation was performed on 2026-11-17 using the local REDCap 17.6.1 development installation, headless Chromium 159, and disposable project 562. The checks exercised REDCap's actual survey validation and persistence pipeline and verified saved answers in the database.

| Browser coverage | Result |
| --- | --- |
| Single-page and paginated surveys; normal submission and Save & Return Later; intact proof, expired proof, and lost survey session | Passed all 12 combinations; incorrect recovery answers remained blocked and no response was saved before re-verification. |
| Challenge types and discovery | Image, bare/simple math, complex math, and custom challenges passed. Invalid JSON and invalid types remained protected by math; explicit `none` skipped the challenge. A later-section tag protected a single-page survey and remained outside the supported first-page scope when pagination was enabled. |
| Saved responses and continuation | Text, numeric, radio, checkbox, date, Unicode, and multiline answers retained; Next/Previous, return-code entry in a fresh browser session, repeated Save & Return, and completion passed. |
| Native server validation | Invalid numeric submissions redisplayed the normal survey with the other answers retained; correcting the value saved successfully, including after proof expiry and session loss. |
| Native file uploads in both layouts and with both submission actions | Valid upload contexts retained files after proof expiry. Lost contexts restored the other answers and displayed a re-upload notice; uploading again and saving passed. |
| Authenticated editor | User confirmed normal loading, changing Re-use challenge, saving and reopening, and restoring the original value. |
| Editor permission and draft errors | Visible errors and Cancel-only dialogs passed with the native jQuery UI widget and simulated server replies; the PHP permission/draft guards are covered by the automated suite. |

Browser testing found and corrected silent attachment loss after survey-session loss. Regression tests cover retaining owned upload contexts, returning a filled form for unowned contexts, and saving a replacement upload. Project annotations and pagination were restored after testing; synthetic responses and attachments remain in project 562 for inspection. These results cover this local installation and Chromium; other REDCap installations and browsers were not exercised.

## November 19 live checks — main REDCap 17.6.0, PID 355, headless Chrome 157

Each recovery check opened a public survey link in a fresh browser profile with response capture enabled. After solving the first CAPTCHA, the browser cleared its survey cookies, selected **No** for `yn`, and confirmed the Stop Action. The recovery page retained the submission. An incorrect CAPTCHA answer left the project record, data-row, and survey-response counts unchanged in both runs.

| Check | Browser result | Verified in REDCap |
| --- | --- | --- |
| Second arm’s public link | The CAPTCHA appeared before the survey questions. A correct answer opened the survey. | The public link belonged to the first event of the temporary second arm. |
| Discard after recovery, arm 2 | The configured Stop Action acknowledgement appeared after re-verification and also with the survey session intact. | REDCap logged that the Stop Action discarded the response. Neither run added a record, field value, or survey response. |
| Save after recovery, arm 1 | The browser displayed the default thank-you page in the first check and the configured Stop Action acknowledgement in the final check. | Synthetic records 15 and 16 both contained the exact verified answer in `nc2_resp`, the stop choice `yn = 0`, and `form_1_complete = 2`. Both public responses had completion timestamps. Record 14 from the original run had the same stop choice and status with an empty `nc2_resp`. |

The original single-arm setup, math CAPTCHA annotation, and Stop Action settings were restored. Records 14–16 remain in PID 355 for inspection.
