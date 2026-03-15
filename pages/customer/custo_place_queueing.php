<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Place Queueing Customer</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h2">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h2">
  <style>
    body.customer-layout.customer-page--queue {
      background: #d8d8d8;
    }

    .queueing-page {
      width: 100%;
      display: grid;
      place-items: center;
      padding: clamp(32px, 7vw, 80px) 16px;
    }

    .queueing-panel {
      width: min(100%, 760px);
      background: linear-gradient(180deg, #f8ad2b 0%, #ff8d2c 100%);
      border-radius: 14px;
      padding: 14px 20px 28px;
      box-shadow: 0 14px 28px rgba(0, 0, 0, 0.18);
    }

    .queueing-title {
      margin: 0 0 14px;
      font-size: clamp(16px, 2.2vw, 23px);
      font-weight: 800;
      font-style: italic;
      color: #3e1300;
      letter-spacing: 0.2px;
      text-transform: uppercase;
    }

    .queueing-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: clamp(12px, 2.8vw, 28px);
    }

    .queueing-card {
      background: #f7f7f7;
      border-radius: 14px;
      text-decoration: none;
      color: #111111;
      min-height: 140px;
      display: grid;
      place-items: center;
      padding: 14px 10px 12px;
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .queueing-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 18px rgba(0, 0, 0, 0.16);
    }

    .queueing-card img {
      width: clamp(58px, 8.8vw, 84px);
      height: auto;
      margin-bottom: 8px;
    }

    .queueing-card span {
      font-size: clamp(14px, 2vw, 25px);
      font-weight: 500;
      text-transform: uppercase;
      line-height: 1;
      letter-spacing: 0.2px;
    }

    @media (max-width: 700px) {
      .queueing-grid {
        grid-template-columns: 1fr;
      }

      .queueing-panel {
        width: min(100%, 420px);
        padding: 16px;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--queue">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="queueing-page">
  <section class="queueing-panel" aria-labelledby="queueing-title">
    <h2 id="queueing-title" class="queueing-title">Choose A Service</h2>

    <div class="queueing-grid">
      <a href="/pages/customer/custo1_printing_option.php" class="queueing-card">
        <img src="/assets/images/CARD_PRINTING.png" alt="Printing">
        <span>Printing</span>
      </a>

      <a href="/pages/customer/custo1_repair_option.php" class="queueing-card">
        <img src="/assets/images/CARD_REPAIR.png" alt="Repair">
        <span>Repair</span>
      </a>

      <a href="/pages/customer/custo1_installation_option.php" class="queueing-card">
        <img src="/assets/images/CARD_INSTALLATION.png" alt="Installation">
        <span>Installation</span>
      </a>
    </div>
  </section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

</body>
</html>
