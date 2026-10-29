-- 1. Remove term assignments to nodes for all terms under "Phone Confirmation"
DELETE nt FROM `node_terms` nt
INNER JOIN `taxonomy_terms` tt ON nt.`term_id` = tt.`id`
INNER JOIN `taxonomy_vocabularies` tv ON tt.`vocabulary_id` = tv.`id`
WHERE tv.`name` = 'Phone Confirmation' OR tv.`machine_name` = 'phone_confirmation';

-- 2. Delete all terms belonging to "Phone Confirmation"
DELETE tt FROM `taxonomy_terms` tt
INNER JOIN `taxonomy_vocabularies` tv ON tt.`vocabulary_id` = tv.`id`
WHERE tv.`name` = 'Phone Confirmation' OR tv.`machine_name` = 'phone_confirmation';

-- 3. Delete the "Phone Confirmation" vocabulary record itself
DELETE FROM `taxonomy_vocabularies`
WHERE `name` = 'Phone Confirmation' OR `machine_name` = 'phone_confirmation';

-- 1. Remove term assignments to nodes for all terms under this vocabulary
DELETE nt FROM `node_terms` nt
INNER JOIN `taxonomy_terms` tt ON nt.`term_id` = tt.`id`
INNER JOIN `taxonomy_vocabularies` tv ON tt.`vocabulary_id` = tv.`id`
WHERE tv.`name` = 'Phone numbers' OR tv.`machine_name` = 'phone_numbers';

-- 2. Delete all terms belonging to this vocabulary
DELETE tt FROM `taxonomy_terms` tt
INNER JOIN `taxonomy_vocabularies` tv ON tt.`vocabulary_id` = tv.`id`
WHERE tv.`name` = 'Phone numbers' OR tv.`machine_name` = 'phone_numbers';

-- 3. Delete the vocabulary record itself
DELETE FROM `taxonomy_vocabularies`
WHERE `name` = 'Phone numbers' OR `machine_name` = 'phone_numbers';