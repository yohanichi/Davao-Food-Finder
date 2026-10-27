# Davao Food Finder

A small server-rendered site using HTML, CSS, JavaScript, and PHP. No Laravel, Node.js, npm, or frontend build step is required.

## Requirements
- PHP 8.1 or newer with the `curl` and `mbstring` extensions enabled
- Composer, to install the PHP dependencies
- The `zip` and `xml` PHP extensions if you want to read Excel files (`.xlsx` or `.xls`)

## Run the app (Windows PowerShell)
1. Open PowerShell in the repository folder (the folder containing `davao_food_finder`) and enter the app folder:
   ```powershell
   cd .\davao_food_finder
   ```
2. Install the PHP dependencies:
   ```bash
   composer install
   ```
3. (Optional) Set an OpenAI API key in this PowerShell window to enable AI answers and live web search. The secure prompt hides the key while you enter it:
   ```powershell
   $secureKey = Read-Host 'OpenAI API key' -AsSecureString
   $env:OPENAI_API_KEY = [System.Net.NetworkCredential]::new('', $secureKey).Password
   ```
   Verify PHP can see that a key is set without printing it:
   ```powershell
   php -r "echo trim((string) getenv('OPENAI_API_KEY')) === '' ? 'PHP: MISSING' : 'PHP: SET';"
   ```
   If a PHP server is already running, stop it first with **Ctrl+C** in its terminal. If there is no accessible terminal, stop the process using its current PID. For example:
   ```powershell
   Stop-Process -Id 7632
   ```
   Only use `7632` if you have confirmed it is still the PHP server process; process IDs can change between runs.
4. Start the server from this same PowerShell window so it inherits the key:
   ```powershell
   Set-Location 'The file location of this app'
   & 'C:\xampp\php\php.exe' -d extension=gd -S 127.0.0.1:8080 -t .
   ```
   If XAMPP or the project is installed elsewhere, adjust the PHP executable and project folder paths. Without a key, the app can still show matches from the local dataset. Keep the key on the server: do not put it in `Main.html` or browser JavaScript, and do not commit a real key to source control. The app is configured to use `gpt-4o-mini`.
5. Open [http://127.0.0.1:8080](http://127.0.0.1:8080) in your browser. Keep the PowerShell window open while using the app; press **Ctrl+C** there to stop the server.

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

## Notes
- The model is fixed to `gpt-4o-mini` to avoid accidentally using a more expensive model through an `OPENAI_MODEL` environment setting. The project calls the Responses API REST endpoint with its web search tool directly; check model access, web search availability, and API key status in the OpenAI dashboard if live search cannot respond.
- Put restaurant and review datasets in `data/`. CSV files are read directly; Excel files (`.xlsx`/`.xls`) require the PHP extensions listed above. The included `ph_restos_2025.csv` and `ph_reviews_2025.csv` files are already in `data/`.
- The review CSV is aggregated by `StoreId` and can take a little time on the first request. After the cache is built, normal requests should be faster.
- If you have multiple copies of the same restaurant/review dataset in `data/`, review counts can be double-counted. Prefer keeping one current version of each dataset.
- Web information is generated from live search and displayed with source links. Local result cards remain grounded in the supplied dataset; the app does not fabricate missing prices, addresses, or review scores.
