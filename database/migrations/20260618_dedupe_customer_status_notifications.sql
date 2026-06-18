-- Keep one customer-facing status notification per real queue event.
-- Short legacy rows such as "Your printing (P20260611-0001) is now ONGOING"
-- are soft-deleted when the fuller Queue ID status message exists.

WITH status_patterns(status_key, full_regex, short_regex) AS (
  VALUES
    ('APPROVED', '\(Queue ID:[[:space:]]*[^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(APPROVED)\.', '^Your[[:space:]]+[^()]+[[:space:]]+\([^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(APPROVED)\.?[[:space:]]*$'),
    ('ONGOING', '\(Queue ID:[[:space:]]*[^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(ONGOING)\.', '^Your[[:space:]]+[^()]+[[:space:]]+\([^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(ONGOING)\.?[[:space:]]*$'),
    ('FOR PICK-UP', '\(Queue ID:[[:space:]]*[^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(FOR[[:space:]-]+PICK[[:space:]-]*UP)\.', '^Your[[:space:]]+[^()]+[[:space:]]+\([^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(FOR[[:space:]-]+PICK[[:space:]-]*UP)\.?[[:space:]]*$'),
    ('DONE', '\(Queue ID:[[:space:]]*[^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(DONE)\.', '^Your[[:space:]]+[^()]+[[:space:]]+\([^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(DONE)\.?[[:space:]]*$'),
    ('CANCELLED', '\(Queue ID:[[:space:]]*[^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(CANCELLED|CANCELED)\.', '^Your[[:space:]]+[^()]+[[:space:]]+\([^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(CANCELLED|CANCELED)\.?[[:space:]]*$')
),
redundant_notifications AS (
  SELECT redundant.id
  FROM notifications AS redundant
  JOIN users AS target_user ON target_user.id = redundant.user_id
  JOIN notifications AS keeper
    ON keeper.user_id = redundant.user_id
   AND COALESCE(keeper.reference_id, 0) = COALESCE(redundant.reference_id, 0)
   AND keeper.id <> redundant.id
   AND keeper.deleted_at IS NULL
   AND LOWER(TRIM(COALESCE(keeper.type, 'queue'))) IN ('status_update', 'queue_cancelled')
  JOIN status_patterns AS pattern
    ON keeper.message ~* pattern.full_regex
   AND redundant.message ~* pattern.short_regex
  WHERE redundant.deleted_at IS NULL
    AND LOWER(TRIM(COALESCE(NULLIF(target_user.role, ''), 'customer'))) <> 'admin'
    AND redundant.message !~* '\(Queue ID:'
)
UPDATE notifications AS n
SET deleted_at = NOW()
FROM redundant_notifications AS redundant
WHERE n.id = redundant.id;

CREATE OR REPLACE FUNCTION public.servitech_add_notification_secure(
  target_user_id INTEGER,
  notification_type TEXT,
  notification_reference_id BIGINT,
  notification_message TEXT,
  notification_event_key TEXT,
  include_deleted_in_dedupe BOOLEAN DEFAULT FALSE
)
RETURNS VOID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
DECLARE
  caller_user_id INTEGER := public.servitech_current_user_id();
  normalized_type TEXT := COALESCE(NULLIF(TRIM(notification_type), ''), 'queue');
  normalized_message TEXT := TRIM(COALESCE(notification_message, ''));
  normalized_event_key TEXT := TRIM(COALESCE(notification_event_key, ''));
  event_identity TEXT;
  target_is_customer BOOLEAN := FALSE;
  status_regex TEXT := '';
  short_status_regex TEXT := '';
  full_status_regex TEXT := '';
BEGIN
  IF caller_user_id IS NULL OR target_user_id IS NULL OR normalized_message = '' THEN
    RAISE EXCEPTION 'Invalid notification request.';
  END IF;

  IF target_user_id <> caller_user_id AND NOT public.servitech_is_admin() THEN
    IF notification_reference_id IS NULL
       OR NOT EXISTS (
         SELECT 1
         FROM public.queues q
         JOIN public.users target ON target.id = target_user_id
         WHERE q.id = notification_reference_id
           AND q.user_id = caller_user_id
           AND LOWER(TRIM(target.role)) = 'admin'
       ) THEN
      RAISE EXCEPTION 'Notification target is not allowed.';
    END IF;
  END IF;

  event_identity := COALESCE(NULLIF(normalized_event_key, ''), MD5(normalized_message));

  SELECT LOWER(TRIM(COALESCE(NULLIF(role, ''), 'customer'))) <> 'admin'
  INTO target_is_customer
  FROM public.users
  WHERE id = target_user_id
  LIMIT 1;

  IF COALESCE(target_is_customer, FALSE) AND notification_reference_id IS NOT NULL THEN
    status_regex := CASE
      WHEN normalized_message ~* 'is[[:space:]]+now[[:space:]]+APPROVED' THEN 'APPROVED'
      WHEN normalized_message ~* 'is[[:space:]]+now[[:space:]]+ONGOING' THEN 'ONGOING'
      WHEN normalized_message ~* 'is[[:space:]]+now[[:space:]]+FOR[[:space:]-]+PICK[[:space:]-]*UP' THEN 'FOR[[:space:]-]+PICK[[:space:]-]*UP'
      WHEN normalized_message ~* 'is[[:space:]]+now[[:space:]]+DONE' THEN 'DONE'
      WHEN normalized_message ~* 'is[[:space:]]+now[[:space:]]+(CANCELLED|CANCELED)' THEN 'CANCELLED|CANCELED'
      ELSE ''
    END;

    IF status_regex <> '' THEN
      full_status_regex := '\(Queue ID:[[:space:]]*[^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(' || status_regex || ')\.';
      short_status_regex := '^Your[[:space:]]+[^()]+[[:space:]]+\([^)]+\)[[:space:]]+is[[:space:]]+now[[:space:]]+(' || status_regex || ')\.?[[:space:]]*$';

      IF normalized_message ~* short_status_regex
         AND normalized_message !~* '\(Queue ID:'
         AND EXISTS (
           SELECT 1
           FROM public.notifications n
           WHERE n.user_id = target_user_id
             AND COALESCE(n.reference_id, 0) = COALESCE(notification_reference_id, 0)
             AND n.deleted_at IS NULL
             AND n.message ~* full_status_regex
           LIMIT 1
         ) THEN
        RETURN;
      END IF;
    END IF;
  END IF;

  PERFORM pg_advisory_xact_lock(hashtext(CONCAT_WS(
    '|', target_user_id::TEXT, LOWER(normalized_type),
    COALESCE(notification_reference_id, 0)::TEXT, event_identity
  )));

  INSERT INTO public.notifications (
    user_id, type, reference_id, message, event_key, is_read, created_at
  )
  SELECT target_user_id, normalized_type, notification_reference_id,
         normalized_message, NULLIF(normalized_event_key, ''), FALSE, NOW()
  WHERE NOT EXISTS (
    SELECT 1
    FROM public.notifications n
    WHERE n.user_id = target_user_id
      AND LOWER(TRIM(COALESCE(n.type, 'queue'))) = LOWER(normalized_type)
      AND COALESCE(n.reference_id, 0) = COALESCE(notification_reference_id, 0)
      AND COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
        = event_identity
      AND (include_deleted_in_dedupe OR n.deleted_at IS NULL)
  )
  ON CONFLICT DO NOTHING;

  IF COALESCE(target_is_customer, FALSE) AND status_regex <> '' THEN
    UPDATE public.notifications AS redundant
    SET deleted_at = NOW()
    FROM public.notifications AS keeper
    WHERE redundant.user_id = target_user_id
      AND redundant.deleted_at IS NULL
      AND keeper.deleted_at IS NULL
      AND keeper.user_id = redundant.user_id
      AND COALESCE(keeper.reference_id, 0) = COALESCE(redundant.reference_id, 0)
      AND keeper.id <> redundant.id
      AND COALESCE(redundant.reference_id, 0) = COALESCE(notification_reference_id, 0)
      AND LOWER(TRIM(COALESCE(keeper.type, 'queue'))) IN ('status_update', 'queue_cancelled')
      AND redundant.message !~* '\(Queue ID:'
      AND keeper.message ~* full_status_regex
      AND redundant.message ~* short_status_regex;
  END IF;
END;
$$;
