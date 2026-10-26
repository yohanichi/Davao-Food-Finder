# Davao Food Finder — conversational PHP prototype

A small server-rendered site using plain HTML/CSS/JavaScript + PHP. No Laravel, Node, npm, Vue, React, or frontend build step is required.

## Requirements
- PHP 8.1+ with `curl`, `mbstring`, and `gd` extensions
- Composer
- `zip` and `xml` PHP extensions may also be required by PhpSpreadsheet for Excel files

## Setup
1. Extract this project folder.
2. Put all your dataset files in `data/`. CSV files named `restos.csv`, `reviews.csv`, `ph_restos_2025.csv`, `ph_reviews_2025.csv` or other `.csv` files are auto-detected. The project ZIP includes the two datasets previously uploaded in this conversation; if you have newer `restos.csv` / `reviews.csv` versions, copy them into `data/` too.
3. Install dependencies in the project directory:
   ```bash
   composer install
   ```
   If Composer reports that `ext-gd` is missing, enable `extension=gd` in the active `php.ini` first. In XAMPP, this is usually `C:\xampp\php\php.ini`; restart your terminal after changing it.
4. Set your OpenAI API key as a server environment variable:
   - macOS/Linux:
     ```bash
     export OPENAI_API_KEY='paste-your-key-here'
     php -d extension=gd -S 127.0.0.1:8080
     ```
   - Windows PowerShell:
     ```powershell
     $env:OPENAI_API_KEY = 'paste-your-key-here'
     php -d extension=gd -S 127.0.0.1:8080
     ```
   The app uses the `gpt-5.5` model with OpenAI web search by default. If your API account uses a different supported Responses API model, set `OPENAI_MODEL` before starting PHP, for example `$env:OPENAI_MODEL = 'your-supported-model'`.
   Keep the key on the server. Never place it in `Main.html` or browser JavaScript, and do not commit a real key to a public repository. If a key was shared or committed, revoke it and create a replacement in the OpenAI dashboard.
5. Start the server from the project directory:
   ```bash
   php -d extension=gd -S 127.0.0.1:8080
   ```
6. Open http://127.0.0.1:8080/Main.html. In VS Code, you can also use **Run and Debug → Launch Chrome against localhost** (F5), which starts the PHP server automatically.

Do not open `Main.html` directly as a `file://` URL or serve it with a static-only preview extension: the search form needs PHP to handle `api.php`.

No `php artisan serve` or `npm run dev` is needed. Those commands are for Laravel/Node projects, which this intentionally is not.

## What works
- Chat-style search with multi-turn follow-ups such as “show me more”, “what about Japanese food?”, or “similar but cheaper”.
- OpenAI's Responses API searches the live web for each request and returns structured restaurant recommendations that appear as cards with cuisine, available ratings, price, location, directions, and source links. Local Davao City dataset matches are shown separately as additional recommendations. Conversation history supports follow-up questions. No separate OpenAI SDK install is needed for this PHP project.
- OpenAI model usage and built-in web search may incur API charges.
- Results remain grouped under the query that produced them. Straightforward restaurant requests show results without repetitive canned replies; OpenAI can respond conversationally when an answer is useful.
- CSVs are scanned in a streaming manner; imported place records are cached in `data/.places-cache.json` to avoid re-reading large files every query. The cache is rebuilt when dataset file names, sizes, or modified times change. To force a rebuild, delete that cache file.
- City filtering requires the detected `City` field to contain `Davao City`. Other Davao Region cities are excluded.
- Restaurant details show dataset ratings/reviewer counts and a Google Maps search link. Price and address details show “not available” if the dataset doesn't contain them.
- If OpenAI or web search is unconfigured or unavailable, relevant local matching is still shown as a fallback.

## Notes
- The default model is `gpt-5.5`. The project calls the Responses API REST endpoint with its web search tool directly; check model access, web search availability, and API key status in the OpenAI dashboard if live search cannot respond.
- The review CSV is aggregated by `StoreId` and can take a little time on the first request. After the cache is built, normal requests should be faster.
- If you have multiple copies of the same restaurant/review dataset in `data/`, review counts can be double-counted. Prefer keeping one current version of each dataset.
- Web information is generated from live search and displayed with source links. Local result cards remain grounded in the supplied dataset; the app does not fabricate missing prices, addresses, or review scores.
