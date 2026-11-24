-- The installer preflight establishes connection_type from the actual table,
-- including retained tables with an absent or already-current schema marker.
-- Do not repeat an unconditional ALTER after the preflight repair.
SELECT 1;
