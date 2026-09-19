INSERT INTO settings (key, value) VALUES
('google_maps_api_key', ''), ('timezone', 'America/New_York'), ('default_speed_mph', '110'),
('default_max_delay_risk', '0.20'), ('candidate_routes', '25'), ('departure_interval_minutes', '15'),
('cruising_fuel_rate_gpm', '10'),
('collection_interval_minutes', '60'), ('google_data_storage_authorized', 'no'),
('login_rate_limit', '5'), ('login_lockout_minutes', '15'),
('password_min_strength', 'strong'), ('password_min_length', '12'),
('automation_enabled', 'yes'), ('automation_interval_minutes', '60'),
('automation_speed_mph', '110'), ('automation_profile', 'balanced'), ('automation_max_risk', '0.20'),
('telemetry_interval_minutes', '15'), ('dashboard_banner', ''),
('simulator_crash_base_percent', '1'), ('simulator_crash_120_percent', '14'), ('simulator_crash_145_percent', '14'),
('simulator_weather_percent', '5'), ('simulator_flat_tire_percent', '5'),
('simulator_headwind_percent', '1'), ('simulator_tailwind_percent', '1'),
('simulator_police_percent', '5'), ('simulator_road_event_percent', '5'),
('simulator_load_driver', '200'), ('simulator_load_fuel_cell', '200'),
('simulator_load_additional_spare', '200'), ('simulator_load_cruising_tires', '0'),
('simulator_load_cruising_tune', '50'), ('simulator_load_tuned_up', '0'), ('simulator_load_radio_scanner', '1'),
('simulator_load_radar_scanner', '5'), ('simulator_load_radar_jammer', '25'),
('simulator_driver_names', E'Doug Tabbut\nDunadel Daryoush\nArne Toman\nChris Duerden\nSafi Barqawi\nChris Benvie\nJames Allen\nKale Odhner\nSamuel Lurie\nChris Allen\nMatt Fried\nChristopher Stowell\nBerkeley Chadwick\nCarl Dietz\nJason Adkins\nMark Spence\nSean Petr\nDave Black\nDan Huang\nEd Bolian\nStephen Thomas\nTommy Thomas\nSteven Groh\nTroy Schneider\nAndrew Calore\nRyan Stark\nDavid Risch\nNik Krueger\nWesley Vigh\nChristopher Michael\nTommy Davies\nRob Pickup\nRobert Pryer\nRomuald Clariond\nSeth Rose\nCameron Davis\nAaron Tulin\nAlex Roy\nDave Maher\nCory Welles\nJohn Levie\nJoe Petralia\nYumi Dietz\nBen Preston\nElijah Dietz\nTaylor Hull\nHunter Robinson\nAndrew Rodgers\nScott Saier\nTim Daley\nWilliam Shafer\nMiles Compton\nSyed Ahmed\nTravis Hilton\nArt Ashmore\nFred Ashmore\nChris Taylor\nAdam Swetlik\nRichard Rawlings\nDennis Collins')
ON CONFLICT (key) DO NOTHING;

DELETE FROM settings WHERE key = 'simulator_mechanical_failure_percent';

INSERT INTO simulator_vehicles
(id,name,mpg_below_35,mpg_35_70,mpg_above_70,capacity,top_speed,tuned_top_speed,max_fuel_cells,max_load) VALUES
('audi-s6','2016 Audi S6',18,27,5,19.8,155,175,2,1300),
('bmw-m5-competition','BMW M5 Competition',15,21,5.5,20.1,155,190,3,1200),
('cadillac-ats','2016 Cadillac ATS',22,26,8.5,16,140,155,2,1300),
('mercedes-cl55-amg','2004 Mercedes CL55 AMG',13,19,7.2,23.2,155,186,3,900),
('ford-crown-victoria','2007 Ford Crown Victoria',15,23,12,19,140,140,3,1400),
('saab-9-5-aero','2008 Saab 9-5 Aero',17,26,11.7,18,155,160,1,1200),
('toyota-celica-gts','2001 Toyota Celica GTS',20,29,17.5,14.5,115,140,2,800),
('lexus-sc400','1995 Lexus SC400',16,20,10,20.6,135,150,3,1000)
ON CONFLICT (id) DO NOTHING;

WITH load_capacity_upgrade AS (
    INSERT INTO settings (key, value) VALUES ('simulator_vehicle_load_defaults_version', '1')
    ON CONFLICT (key) DO NOTHING RETURNING key
)
UPDATE simulator_vehicles SET max_load=CASE id
  WHEN 'audi-s6' THEN 1300 WHEN 'bmw-m5-competition' THEN 1200
  WHEN 'cadillac-ats' THEN 1300 WHEN 'mercedes-cl55-amg' THEN 900
  WHEN 'ford-crown-victoria' THEN 1400 WHEN 'saab-9-5-aero' THEN 1200
  WHEN 'toyota-celica-gts' THEN 800 WHEN 'lexus-sc400' THEN 1000
  ELSE max_load END, updated_at=now()
WHERE EXISTS (SELECT 1 FROM load_capacity_upgrade);

WITH crown_victoria_mpg_fix AS (
    INSERT INTO settings (key, value)
    VALUES ('simulator_vehicle_defaults_version', '2')
    ON CONFLICT (key) DO NOTHING
    RETURNING key
)
UPDATE simulator_vehicles
SET mpg_above_70 = 12, updated_at = now()
WHERE id = 'ford-crown-victoria'
  AND mpg_above_70 = 121
  AND EXISTS (SELECT 1 FROM crown_victoria_mpg_fix);

WITH fuel_rate_upgrade AS (
    INSERT INTO settings (key, value)
    VALUES ('cruising_fuel_rate_default_version', '2')
    ON CONFLICT (key) DO NOTHING
    RETURNING key
)
UPDATE settings
SET value = '10'
WHERE key = 'cruising_fuel_rate_gpm'
  AND value = '5'
  AND EXISTS (SELECT 1 FROM fuel_rate_upgrade);

INSERT INTO segments (name,start_node,end_node,origin,destination,timezone) VALUES
('redball_to_you','redball','you','142 E 31st St, New York, NY 10016','6965 Truck World Blvd, Hubbard, OH 44425','America/New_York'),
('redball_to_har','redball','har','142 E 31st St, New York, NY 10016','257 Bow Creek Rd, Grantville, PA 17028','America/New_York'),
('har_to_cole','har','cole','257 Bow Creek Rd, Grantville, PA 17028','10636 Jacksontown Rd, Thornville, OH 43076','America/New_York'),
('har_to_nash','har','nash','257 Bow Creek Rd, Grantville, PA 17028','2331 TN-46, Dickson, TN 37055','America/New_York'),
('you_to_coln','you','coln','6965 Truck World Blvd, Hubbard, OH 44425','7332 E, 7332 OH-37, Sunbury, OH 43074','America/New_York'),
('you_to_big','you','big','6965 Truck World Blvd, Hubbard, OH 44425','109 Circle Rd, Big Springs, NE 69122','America/New_York'),
('cole_to_stl','cole','stl','10636 Jacksontown Rd, Thornville, OH 43076','3410 George St, Highland, IL 62249','America/New_York'),
('coln_to_stl','coln','stl','7332 E, 7332 OH-37, Sunbury, OH 43074','3410 George St, Highland, IL 62249','America/New_York'),
('cole_to_nash','cole','nash','10636 Jacksontown Rd, Thornville, OH 43076','2331 TN-46, Dickson, TN 37055','America/New_York'),
('coln_to_nash','coln','nash','7332 E, 7332 OH-37, Sunbury, OH 43074','2331 TN-46, Dickson, TN 37055','America/New_York'),
('big_to_cov','big','cov','109 Circle Rd, Big Springs, NE 69122','10950 Black Rock Rd, Beaver, UT 84713','America/Denver'),
('big_to_den','big','den','109 Circle Rd, Big Springs, NE 69122','2808 Colorado Blvd, Idaho Springs, CO 80452','America/Denver'),
('stl_to_den','stl','den','3410 George St, Highland, IL 62249','2808 Colorado Blvd, Idaho Springs, CO 80452','America/Chicago'),
('stl_to_elr','stl','elr','3410 George St, Highland, IL 62249','550 S Walbaum Rd, Calumet, OK 73014','America/Chicago'),
('nash_to_elr','nash','elr','2331 TN-46, Dickson, TN 37055','550 S Walbaum Rd, Calumet, OK 73014','America/Chicago'),
('den_to_cov','den','cov','2808 Colorado Blvd, Idaho Springs, CO 80452','10950 Black Rock Rd, Beaver, UT 84713','America/Denver'),
('elr_to_bar','elr','bar','550 S Walbaum Rd, Calumet, OK 73014','2611 Fisher Blvd, Barstow, CA 92311','America/Chicago'),
('cov_to_bar','cov','bar','10950 Black Rock Rd, Beaver, UT 84713','2611 Fisher Blvd, Barstow, CA 92311','America/Denver'),
('bar_to_portofino','bar','portofino','2611 Fisher Blvd, Barstow, CA 92311','260 Portofino Way, Redondo Beach, CA 90277','America/Los_Angeles')
ON CONFLICT (name) DO NOTHING;
