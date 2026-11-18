# holidaygogogo-codeigniter

Extra Routes
	- domain/ghl-details
		show all the conversation without leads, be it (unsynced / missed out)
	- domain/ghl-custom-field-viewer
		show all custom fields, user select one to view all contacts with that field and its saved value
	
1. Copy the environment file
	cp .env.example .env

2. Update your credentials in .env
   Database host, name, username, password
   Any API keys or custom config

3. Run DB patches
	- Migration: `php index.php run_sql_patches`
	- Seeders: `php index.php run_seeders`

The booking maintenance cron (`php index.php Cron index`) sends Draft BC and
Pending BC bell reminders. Change the two thresholds under **Settings → Booking
Reminders**. Both default to 24 hours, and 0 disables that stage. Owners can grant
other staff access through **Navigation Access**. Run the SQL patches before running cron.

4. GoHighLevel sync
	- Users: full sync on every run via `php index.php Cron syncGhlUsers`
	- Custom Fields: full sync on every run via `php index.php Cron syncGhlCustomFields`
	- Contacts recent x days sync: `php index.php Cron syncGhlContacts` | Sync full `php index.php Cron syncGhlContacts --full`. On default is 3 days, but can be changed in the .env file. 3 days is enough just to conver any missed data. 
	- Conversations recent x days sync: `php index.php Cron syncGhlConversations` | Sync full `php index.php Cron syncGhlConversations --full`. On default is 3 days, but can be changed in the .env file. Currently set as 5 days to check all the updates. 
	- Messages recent x days sync: `php index.php Cron syncGhlMessages` | Sync full `php index.php Cron syncGhlMessages --full`. On default is 3 days, but can be changed in the .env file.  Currently set as 5 days to check all the updates. 
	- Combined hourly GHL module cron: `php index.php Cron syncGhlModules`
		- At minute 10: sync users, contacts, conversations, and messages.
		- At minute 40: process leads, lead conversions, and lead ownership.

5. Process the GoHighLevel data and create leads 
	- Process leads from synced GHL messages: `php index.php Cron process_ghl_leads`
	- Process leads with a custom batch size: `php index.php Cron process_ghl_leads 250`
	- Re-Process leads: `php index.php Cron process_ghl_leads --rebuild`
	- Follow up status is calculated during lead processing and saved as `follow_up_status`.

6. Process the GoHighLevel leads and check for convertion
	- Process Convertion: `php index.php Cron process_ghl_lead_conversions`
	- Process Convertion with a custom batch size: `php index.php Cron process_ghl_lead_conversions 200`
	- Re-Process convertion: `php index.php Cron process_ghl_lead_conversions --rebuild`

7. Process Lead Ownership data
	- This creates/updates `ghl_lead_ownership` for `Report > Lead Ownership` and `Report > Lead Ownership Data`.
	- Run this after GHL leads and conversions are processed, so assignment, response, follow up, and conversion data are current.
	- Assigned Owned: the owner is the current processed assigned user.
	- Reply Owned: the owner is not assigned, but replied more than 2 times in the lead conversation window.
	- Process ownership: `php index.php Cron process_ghl_lead_ownership`
	- Process ownership with a custom batch size: `php index.php Cron process_ghl_lead_ownership 1000`
	- Rebuild ownership table: `php index.php Cron process_ghl_lead_ownership rebuild`
	- Reprocess one processed lead id: `php index.php Cron process_ghl_lead_ownership 1000 185878`

I. Default Commands & order 
	php index.php run_sql_patches
	php index.php Cron syncGhlUsers
	php index.php Cron syncGhlCustomFields
	php index.php Cron syncGhlContacts --full
	php index.php Cron syncGhlConversations --full
	php index.php Cron syncGhlMessages --full
	php index.php Cron process_ghl_leads --rebuild
	php index.php Cron process_ghl_lead_conversions
	php index.php Cron process_ghl_lead_ownership rebuild

II. Default Daily & order 
	php index.php Cron syncGhlModules

III. Manual Daily Commands & order 
	php index.php Cron syncGhlUsers
	php index.php Cron syncGhlCustomFields
	php index.php Cron syncGhlContacts
	php index.php Cron syncGhlConversations
	php index.php Cron syncGhlMessages
	php index.php Cron process_ghl_leads
	php index.php Cron process_ghl_lead_conversions
	php index.php Cron process_ghl_lead_ownership

8. Tests (GHL leads / ownership)
	The GHL lead tests are plain PHP scripts using in-memory SQLite — no database
	or framework bootstrap needed. Each prints PASS/FAIL lines and exits non-zero
	on failure.

	Test:
	- `GhlProcessedLeadsUpsertStabilityTest.php` — re-processing keeps each lead's
	  id stable (update in place, no duplicate rows).
	- `GhlLeadOwnershipOrphanPruneTest.php` — orphaned ownership rows are pruned;
	  the same lead never appears under two owners.
	- `GhlReplyOwnerThresholdTest.php` — reply owner = at least 2 replies; the
	  assigned owner is never double-counted as a reply owner.
	- `GhlContinueChunkedReplayTest.php` — replays ~20k messages in 5k chunks and
	  asserts continue never creates more leads than a full rebuild.
	- `GhlRebuildContinueParityTest.php` — rebuild and continue split a
	  convert-and-return conversation the same way.


	- Run all GHL lead tests:
		for t in tests/helpers/Ghl*Test.php; do echo "== $t =="; php "$t" || break; done
	- Run a single test:
		php tests/helpers/GhlReplyOwnerThresholdTest.php
