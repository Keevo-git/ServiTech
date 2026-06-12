BEGIN;

-- Admin catalog records are archived by setting active = false. The website does
-- not need row-level DELETE privileges for these public catalog tables.
REVOKE DELETE ON public.services, public.announcements FROM authenticated;

COMMIT;
