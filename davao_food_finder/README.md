# Davao Food Finder

A small server-rendered site using HTML, CSS, JavaScript, PHP, DeepSeek R1, GPT-4o mini, and Playwright. The DeepSeek pipeline is: Playwright raw HTML/text → GPT-4o mini cleaning and structuring → optional DeepSeek-R1 complex curation → GPT-4o mini final response. PHP handles the UI/API and 2025 local dataset, while the Playwright cache limits repeated scans to 12 hours.

## Requirements
- PHP 8.1 or newer with the `curl` and `mbstring` extensions enabled
- Composer, to install the PHP dependencies
- Node.js and Playwright, to crawl current public web pages
- Ollama with a DeepSeek R1 model for free local inference, or a DeepSeek API key
- The `zip` and `xml` PHP extensions if you want to read Excel files (`.xlsx` or `.xls`)

## Run the app (Windows PowerShell)
1. Open PowerShell in the repository folder (the folder containing `davao_food_finder`) and enter the app folder:
   ```powershell
   cd .\davao_food_finder
   ```
2. Install the PHP dependencies:
   ```powershell
   & 'C:\xampp\php\php.exe' 'C:\xampp\php\composer' install
   ```
3. Start Ollama, then install a R1 model in a separate PowerShell window:
   ```powershell
   ollama pull deepseek-r1:8b
   ollama serve
   ```
   Keep Ollama running. The app defaults to `http://127.0.0.1:11434` and model `deepseek-r1:8b`. Optionally set `DEEPSEEK_API_KEY` and `DEEPSEEK_MODEL=deepseek-reasoner` to use hosted DeepSeek instead. DeepSeek API usage is separate from Ollama and may not be free.
4. Install Playwright in the workspace root, or reuse the workspace installation already present:
   ```powershell
   npm install
   npx playwright install chromium
   ```
   Playwright evidence is cached by normalized query for 12 hours in `data/.web-cache`. Identical or equivalent queries reuse cached evidence; each page is also bounded by timeouts.
5. Start the server from this same PowerShell window:
   ```powershell
   Set-Location 'C:\Josh Gutierrez\Thesis 2\DavaoFoodFinder\Davao-Food-Finder-main\Davao-Food-Finder-main\davao_food_finder'
   & 'C:\xampp\php\php.exe' -d extension=gd -S 127.0.0.1:8081 -t .
   ```
   If XAMPP or the project is installed elsewhere, adjust the PHP executable and project folder paths. Without a key, the app can still show matches from the local dataset. Keep the key on the server: do not put it in `Main.html` or browser JavaScript, and do not commit a real key to source control. The app is configured to use `gpt-4o-mini`.
5. Open [http://127.0.0.1:8081](http://127.0.0.1:8081) in your browser. Keep the PowerShell window open while using the app; press **Ctrl+C** there to stop the server. If the terminal reports that port `8081` is already in use, choose another port in the server command and open that same port in your browser.

Run the commands in the same PowerShell window so the optional API key is available to PHP. The PHP server serves `index.php`, which redirects to `Main.html`. Do not open `Main.html` as a `file://` URL or use a static-only preview extension: searches need PHP to handle `api.php`.

On macOS/Linux, use `cd davao_food_finder`, `composer install`, optionally set `OPENAI_API_KEY`, then run `php -S 127.0.0.1:8080` from the same terminal.

## What works
- Chat-style search with multi-turn follow-ups such as “show me more”, “what about Japanese food?”, or “similar but cheaper”.
- OpenAI's Responses API searches the live web for each request and returns structured restaurant recommendations that appear as cards with cuisine, available ratings, price, location, directions, and source links. Local Davao City dataset matches are shown separately as additional recommendations. Conversation history supports follow-up questions. No separate OpenAI SDK install is needed for this PHP project.
- Recommendations are limited to Davao City. Requests for another location receive a brief clarification instead of AI or dataset recommendations; online recommendations are only displayed when their city is verified as Davao City.
- The app uses `gpt-4o-mini`, a lower-cost model. Model tokens and built-in web search may still incur API charges; web search is billed separately from model tokens.
- The page identifies the configured model and updates to the model name returned by OpenAI after a successful AI response.
- Results remain grouped under the query that produced them. Straightforward restaurant requests show results without repetitive canned replies; OpenAI can respond conversationally when an answer is useful.
- CSVs are scanned in a streaming manner; imported place records are cached in `data/.places-cache.json` to avoid re-reading large files every query. The cache is rebuilt when dataset file names, sizes, or modified times change. To force a rebuild, delete that cache file.
- Dataset city filtering requires the detected `City` field to contain `Davao City`. Other Davao Region cities are excluded.
- Restaurant details show dataset ratings/reviewer counts and a Google Maps search link. Price and address details show “not available” if the dataset doesn't contain them.
- If OpenAI or web search is unconfigured or unavailable, relevant local matching is still shown as a fallback.

## Hyperparameter evaluation

The CLI evaluation runner compares temperatures `0.0`, `0.3`, and `0.7` with the `baseline` and `strict_grounding` prompt styles. Top-P remains fixed at `1.0`. The runner can evaluate DeepSeek R1, GPT-4o mini, or both against the same ground-truth queries and the same Playwright evidence cache. This is an exhaustive grid sweep for the selected providers.

The initial labeled dataset is in `evaluation/ground_truth.json`. Its three in-scope queries explicitly ask for Japanese restaurants, coffee shops, and fried-chicken restaurants in Davao City. Expected names come only from rows in `ph_restos_2025.csv` whose `City` contains `Davao City` and whose `FoodType` exactly matches that query category (28 Japanese, 96 Coffee, and 44 Fried Chicken records in the current CSV). This is a transparent taxonomy-derived starting point, not an independent human relevance judgment: review the category labels and cases with your thesis team before reporting results. Names are matched case-insensitively with punctuation ignored.

The same file includes scope checks requesting Japanese restaurants in Tagum City and coffee shops in Cebu City. These verify the Davao City-only constraint; a scope check passes only when the AI marks the request out-of-scope and returns no local or web recommendations. The runner rejects in-scope queries that do not explicitly name Davao City. `evaluation/ground_truth.example.json` shows the file structure if you want to add or replace test cases.

Keep Ollama running for DeepSeek R1 and keep the PHP web server running in another window. For both-provider comparison, also set `OPENAI_API_KEY` before starting PHP. Then run in a second PowerShell window:

```powershell
Set-Location 'C:\Josh Gutierrez\Thesis 2\DavaoFoodFinder\Davao-Food-Finder-main\Davao-Food-Finder-main\davao_food_finder'
& 'C:\xampp\php\php.exe' .\evaluation\run_evaluation.php --require-ai --providers=deepseek,gpt-4o-mini
```

Use `--provider=deepseek` or `--provider=gpt-4o-mini` for a single-model run. The runner sends requests to `http://127.0.0.1:8081/api.php`; for another local port, use `--api-url=http://127.0.0.1:8080/api.php`. API keys remain in the PHP process and are never read or printed by the CLI runner. Hosted GPT usage may incur charges; local Ollama does not require a DeepSeek API key.

Per-query output reports Precision@5 and request latency for Davao City relevance queries, plus pass/fail and latency for the out-of-city scope checks. Precision@5 is `relevant unique names in the first five local results / 5`; failed or non-AI relevance requests receive a score of zero and all failed requests are counted in the error rate. The final table reports mean Precision@5, mean and median end-to-end HTTP latency, error rate, and Davao-only scope violations for each configuration. It also reports a tie-corrected Friedman test across the repeated in-scope query scores when at least two queries are available. The included three relevance queries are an initial benchmark, not a large validation sample; add diverse, independently labeled Davao City queries before making strong statistical claims:

`Q = [12 / (n k (k + 1)) * sum(R_j^2) - 3n(k + 1)] / C`

where `n` is the number of queries, `k` is the number of configurations, `R_j` is the rank sum for configuration `j`, and `C = 1 - sum(t^3 - t) / [n k (k^2 - 1)]` corrects for ties. The p-value uses the chi-square approximation with `k - 1` degrees of freedom. A significant result indicates that at least one configuration differs; it does not identify which pairs differ, so use a post-hoc comparison before making pairwise claims.

## Notes
- DeepSeek and GPT-4o mini are benchmarked with the same PHP API, Playwright evidence cache, temperatures, prompt styles, and ground-truth labels. DeepSeek defaults to local Ollama model `deepseek-r1:8b`; GPT-4o mini uses `OPENAI_API_KEY` and defaults to hosted `gpt-4o-mini`.
- Put restaurant and review datasets in `data/`. CSV files are read directly; Excel files (`.xlsx`/`.xls`) require the PHP extensions listed above. The included `ph_restos_2025.csv` and `ph_reviews_2025.csv` files are already in `data/`.
- The review CSV is aggregated by `StoreId` and can take a little time on the first request. After the cache is built, normal requests should be faster.
- If you have multiple copies of the same restaurant/review dataset in `data/`, review counts can be double-counted. Prefer keeping one current version of each dataset.
- Web information is generated from live search and displayed with source links. Local result cards remain grounded in the supplied dataset; the app does not fabricate missing prices, addresses, or review scores.
