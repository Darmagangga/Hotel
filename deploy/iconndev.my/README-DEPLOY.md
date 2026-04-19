Deploy target: `https://iconndev.my/flux`

Upload structure on server:

1. Upload all contents of folder `flux/` into server folder `/flux`
2. Upload `api/api-hotel.php` into server folder `/api/api-hotel.php`
3. Upload `api/backend/.env` into server folder `/api/backend/.env`

Important:

- Frontend production build already uses base path `/flux/`
- Frontend API calls are configured to hit `https://iconndev.my/api/api-hotel.php/`
- File `api-hotel.php` reads database config from `/api/backend/.env`, so folder `backend` must stay beside `api-hotel.php`

Database:

1. Create a MySQL database on hosting
2. Import `database/hotel_pms_core.sql`
3. Optional: import `database/coa_seed.sql`
4. Optional: import `database/room_master_seed.sql`
5. `database/schema.sql` is included for reference structure

Edit file `api/backend/.env` before upload:

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `HOTEL_DB_HOST`
- `HOTEL_DB_PORT`
- `HOTEL_DB_DATABASE`
- `HOTEL_DB_USERNAME`
- `HOTEL_DB_PASSWORD`
- `APP_KEY` if you want to change the app secret

Quick check after upload:

1. Open `https://iconndev.my/flux`
2. Open browser devtools and confirm JS/CSS load from `/flux/assets/...`
3. Test login and one API request
4. If frontend opens but data fails, the first thing to check is `/api/backend/.env`
