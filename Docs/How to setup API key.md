Google Maps API setup (minimal)
----------------------------------------------
At the end of this, you’ll have:
    A Google Cloud Project
    Billing enabled (required, but controllable)
    Directions API enabled
    A restricted API key
    Hard usage caps so you don’t get surprised

1️⃣ Create / select a Google Cloud project
Go to: https://console.cloud.google.com/
    Log in with your Google account
    In the top bar → click Project selector
    Click New Project
    Name it
    No organization needed. Create it.
✅ From this point on, make sure the project name in the top bar is this project.

2️⃣ Enable billing (required, but safe)
Go to Billing
    Attach a billing account
    You’ll need a credit card
    Confirm billing is linked to your project
Important clarifications:
    ✅ You will not be charged unless you exceed free credit
    ✅ The API literally won’t work without this
    ✅ You can cap spend later (you should)
    This step is unavoidable, but not dangerous.

3️⃣ Enable the correct API (this matters)
Go to APIs & Services → Library
    Search for "Directions API"
    Click it
    Click Enable
    Do the same for "Maps Static API"
🚫 You do not need:
    Maps JavaScript API
    Places API
    Distance Matrix
Enabling only what you need reduces risk.

4️⃣ Create an API key
You may have already been provided a key, if so skip this section
Go to APIs & Services → Credentials
    Click Create credentials → API key
    Copy the key somewhere safe (you’ll paste it into maps_key.py)
    Right now this key is wide open — we fix that next.

5️⃣ Lock the API key down (very important)
    Click your new API key → Edit API key
✅ Application restrictions
        API restrictions (do this)
        Select "Directions API" and "Maps Static API"
        Scroll down and click "Save"
This means even if someone steals your key they cannot use any other Google API. This is the single biggest safety step.

6️⃣ Set spending protection (do not skip)
Go to Billing → Budgets & alerts
    Create a budget:
    Amount: $1
    Add alerts:
        50%
        90%
If something goes wrong, you’ll know immediately.

Go to APIs & Services → Directions API → Quotas
    Set: Requests per day (e.g. 1000)
I skipped this part actually...

Pricing: https://developers.google.com/maps/billing-and-pricing/pricing