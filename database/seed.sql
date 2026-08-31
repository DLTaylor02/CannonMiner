INSERT INTO settings (key, value) VALUES
('google_maps_api_key', ''), ('timezone', 'America/New_York'), ('default_speed_mph', '110'),
('default_max_delay_risk', '0.20'), ('candidate_routes', '25'),
('collection_interval_minutes', '60'), ('google_data_storage_authorized', 'no')
ON CONFLICT (key) DO NOTHING;

INSERT INTO segments (name,start_node,end_node,origin,destination) VALUES
('redball_to_you','redball','you','142 E 31st St, New York, NY 10016','6965 Truck World Blvd, Hubbard, OH 44425'),
('redball_to_har','redball','har','142 E 31st St, New York, NY 10016','257 Bow Creek Rd, Grantville, PA 17028'),
('har_to_cole','har','cole','257 Bow Creek Rd, Grantville, PA 17028','10636 Jacksontown Rd, Thornville, OH 43076'),
('har_to_nash','har','nash','257 Bow Creek Rd, Grantville, PA 17028','2331 TN-46, Dickson, TN 37055'),
('you_to_coln','you','coln','6965 Truck World Blvd, Hubbard, OH 44425','7332 E, 7332 OH-37, Sunbury, OH 43074'),
('you_to_big','you','big','6965 Truck World Blvd, Hubbard, OH 44425','109 Circle Rd, Big Springs, NE 69122'),
('cole_to_stl','cole','stl','10636 Jacksontown Rd, Thornville, OH 43076','3410 George St, Highland, IL 62249'),
('coln_to_stl','coln','stl','7332 E, 7332 OH-37, Sunbury, OH 43074','3410 George St, Highland, IL 62249'),
('cole_to_nash','cole','nash','10636 Jacksontown Rd, Thornville, OH 43076','2331 TN-46, Dickson, TN 37055'),
('coln_to_nash','coln','nash','7332 E, 7332 OH-37, Sunbury, OH 43074','2331 TN-46, Dickson, TN 37055'),
('big_to_cov','big','cov','109 Circle Rd, Big Springs, NE 69122','10950 Black Rock Rd, Beaver, UT 84713'),
('big_to_den','big','den','109 Circle Rd, Big Springs, NE 69122','2808 Colorado Blvd, Idaho Springs, CO 80452'),
('stl_to_den','stl','den','3410 George St, Highland, IL 62249','2808 Colorado Blvd, Idaho Springs, CO 80452'),
('stl_to_elr','stl','elr','3410 George St, Highland, IL 62249','550 S Walbaum Rd, Calumet, OK 73014'),
('nash_to_elr','nash','elr','2331 TN-46, Dickson, TN 37055','550 S Walbaum Rd, Calumet, OK 73014'),
('den_to_cov','den','cov','2808 Colorado Blvd, Idaho Springs, CO 80452','10950 Black Rock Rd, Beaver, UT 84713'),
('elr_to_bar','elr','bar','550 S Walbaum Rd, Calumet, OK 73014','2611 Fisher Blvd, Barstow, CA 92311'),
('cov_to_bar','cov','bar','10950 Black Rock Rd, Beaver, UT 84713','2611 Fisher Blvd, Barstow, CA 92311'),
('bar_to_portofino','bar','portofino','2611 Fisher Blvd, Barstow, CA 92311','260 Portofino Way, Redondo Beach, CA 90277')
ON CONFLICT (name) DO NOTHING;
