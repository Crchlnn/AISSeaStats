-- AISSeaStats 1.2.1 — towboats and pushers, kinds of aids to navigation
-- pusher: the vessel was seen pushing or towing (navigational status 11 or 12, or an inland push-tow / pusher
-- ERI code). Such a vessel often declares the length of its whole convoy, so it is not tagged "very large".
-- aid_type: kind of aid to navigation from AIS message 21 (1 = reference point … 5 = light … 9-12 = cardinal
-- marks … 24-31 = virtual or floating marks), filled from the next messages of each aid.
ALTER TABLE vessel ADD COLUMN IF NOT EXISTS pusher TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE vessel ADD COLUMN IF NOT EXISTS aid_type TINYINT UNSIGNED NULL;
