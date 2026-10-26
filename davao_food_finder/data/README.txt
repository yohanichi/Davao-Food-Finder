Put your restaurant dataset CSV/Excel files in this folder. The current uploaded files are:
- ph_restos_2025(1).csv (restaurant records)
- ph_reviews_2025.csv (review rows, linked by StoreId)
CSV files are streamed row-by-row. The review CSV is aggregated by StoreId to avoid loading all reviews into memory.
Restaurant CSV columns recognized from the supplied file:
StoreId, CompleteStoreName, FoodType, AverageRating, Reviewers, City
Only records whose City contains the phrase "Davao City" are included (e.g. "davao city davao del sur").
Other optional columns such as address, price range, and description are used when present.
