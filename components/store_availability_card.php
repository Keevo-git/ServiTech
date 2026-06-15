<?php
if (!isset($storeAvailability) || !is_array($storeAvailability)) {
    return;
}
$availabilityEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
$availabilityTone = $storeAvailability["regular_queue_allowed"]
    ? "open"
    : ($storeAvailability["effective_status"] ?? "closed");
?>
<section class="store-availability-card store-availability-card--<?= $availabilityEsc($availabilityTone) ?>" aria-labelledby="storeAvailabilityTitle">
  <div class="store-availability-card__main">
    <div>
      <span class="store-availability-card__eyebrow">Store Availability</span>
      <h2 id="storeAvailabilityTitle"><?= $availabilityEsc($storeAvailability["status_label"] ?? "Closed") ?></h2>
      <p><?= $availabilityEsc($storeAvailability["message"] ?? "") ?></p>
    </div>
    <span class="store-availability-card__status"><?= $availabilityEsc($storeAvailability["status_label"] ?? "Closed") ?></span>
  </div>
  <dl class="store-availability-card__details">
    <div><dt>Today's hours</dt><dd><?= $availabilityEsc($storeAvailability["today_hours"] ?? "Closed") ?></dd></div>
    <div><dt>Queue cutoff</dt><dd><?= $availabilityEsc($storeAvailability["queue_cutoff_label"] ?? "Not set") ?></dd></div>
    <div><dt>Online Document Printing</dt><dd>Available</dd></div>
  </dl>
  <?php if (!empty($storeAvailability["upcoming_holidays"])): ?>
    <div class="store-availability-card__holidays">
      <strong>Upcoming closed dates</strong>
      <ul>
        <?php foreach (array_slice($storeAvailability["upcoming_holidays"], 0, 3) as $holiday): ?>
          <li>
            <time datetime="<?= $availabilityEsc($holiday["holiday_date"] ?? "") ?>">
              <?= $availabilityEsc(date("M j, Y", strtotime((string)($holiday["holiday_date"] ?? "")))) ?>
            </time>
            <span><?= $availabilityEsc($holiday["title"] ?? "Closed") ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</section>
