<?php
require_once __DIR__ . '/config/app.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="A secure school command center for fee collections, student records, reports, and daily operations at Arise To Excel Academy.">
    <title>School Command Center | <?= h(SCHOOL_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/ui-refresh.css') ?>">
</head>
<body class="public-home-page">
    <header class="public-home-header">
        <a class="public-home-brand" href="<?= url('index.php') ?>" aria-label="Arise To Excel Academy homepage">
            <img src="<?= asset('images/school-logo.jpg') ?>" alt="">
            <span>
                <strong><?= h(SCHOOL_NAME) ?></strong>
                <small>School Command Center</small>
            </span>
        </a>
        <nav class="public-home-nav" aria-label="Main navigation">
            <a class="public-home-login" href="<?= url('admin/login.php') ?>">
                <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>
                <span>Login</span>
            </a>
        </nav>
    </header>

    <main class="public-home-main">
        <section class="public-home-hero" aria-labelledby="public-home-title">
            <div class="public-home-copy">
                <p class="public-home-eyebrow"><span></span> Learn <i></i> Grow <i></i> Achieve</p>
                <h1 id="public-home-title">Finance, students, and operations in one <span>calm command center.</span></h1>
                <p class="public-home-description">Manage fee collections, student records, balances, reports, and daily school operations in one secure workspace.</p>
                <a class="public-home-primary" href="<?= url('admin/login.php') ?>">
                    <span>Sign in to your account</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
                <p class="public-home-assurance"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secure access for school staff and families</p>
            </div>
            <div class="public-home-visual">
                <div class="public-dashboard-preview" role="img" aria-label="School Command Center dashboard with student, fee, finance, and activity summaries.">
                    <div class="public-dashboard-frame" aria-hidden="true">
                        <div class="public-dashboard-topbar">
                            <div class="public-dashboard-mobile-brand"><img src="<?= asset('images/school-logo.jpg') ?>" alt=""><span>Arise To Excel</span></div>
                            <span class="public-dashboard-menu"><i class="fa-solid fa-bars"></i></span>
                            <span class="public-dashboard-search"><i class="fa-solid fa-magnifying-glass"></i> Search students, payments, classes...</span>
                            <span class="public-dashboard-term"><i class="fa-regular fa-calendar"></i> 2027 · Term 1</span>
                            <span class="public-dashboard-avatar"><i class="fa-solid fa-user"></i></span>
                        </div>
                        <div class="public-dashboard-layout">
                            <aside class="public-dashboard-sidebar">
                                <div class="public-dashboard-side-brand"><img src="<?= asset('images/school-logo.jpg') ?>" alt=""><span><b>Arise To Excel</b><small>School Command Center</small></span></div>
                                <div class="public-dashboard-nav">
                                    <span class="is-active"><i class="fa-solid fa-chart-pie"></i> Dashboard</span>
                                    <span><i class="fa-solid fa-user-graduate"></i> Students</span>
                                    <span><i class="fa-solid fa-wallet"></i> Fees</span>
                                    <span><i class="fa-solid fa-bus-simple"></i> Transport</span>
                                    <span><i class="fa-solid fa-utensils"></i> Feeding</span>
                                    <span><i class="fa-solid fa-chart-column"></i> Reports</span>
                                </div>
                                <div class="public-dashboard-sidebar-user"><span class="public-dashboard-user-dot"></span><span><b>School administrator</b><small>Secure workspace</small></span></div>
                            </aside>
                            <div class="public-dashboard-content">
                                <div class="public-dashboard-heading"><div><small>OVERVIEW</small><h2>School dashboard</h2><p>A clear view of your school's activity.</p></div></div>
                                <div class="public-dashboard-metrics">
                                    <div class="public-dashboard-metric metric-students"><span><i class="fa-solid fa-users"></i> Total students</span><b>124</b><small>Across all classes</small></div>
                                    <div class="public-dashboard-metric metric-collected"><span><i class="fa-solid fa-coins"></i> Fees collected</span><b>KES 84,500</b><small>This term</small></div>
                                    <div class="public-dashboard-metric metric-balance"><span><i class="fa-solid fa-scale-balanced"></i> Fee balance</span><b>KES 18,200</b><small>Outstanding</small></div>
                                    <div class="public-dashboard-metric metric-transport"><span><i class="fa-solid fa-bus"></i> Transport</span><b>12</b><small>Active routes</small></div>
                                </div>
                                <div class="public-dashboard-panels">
                                    <div class="public-dashboard-panel public-dashboard-chart"><div class="public-dashboard-panel-heading"><span><b>Fee collection</b><small>Monthly overview</small></span><i class="fa-solid fa-ellipsis"></i></div><div class="public-dashboard-chart-area"><div class="public-dashboard-axis"><span>100k</span><span>75k</span><span>50k</span><span>25k</span></div><div class="public-dashboard-bars"><i style="--bar-height:38%"></i><i style="--bar-height:55%"></i><i style="--bar-height:46%"></i><i style="--bar-height:72%"></i><i style="--bar-height:63%"></i><i style="--bar-height:91%"></i><i style="--bar-height:78%"></i><i style="--bar-height:100%"></i></div></div><div class="public-dashboard-months"><span>Aug</span><span>Sep</span><span>Oct</span><span>Nov</span><span>Dec</span><span>Jan</span><span>Feb</span><span>Mar</span></div></div>
                                    <div class="public-dashboard-panel public-dashboard-summary"><div class="public-dashboard-panel-heading"><span><b>Account summary</b><small>Income breakdown</small></span><i class="fa-solid fa-ellipsis"></i></div><div class="public-dashboard-ring"><div><b>KES 102k</b><small>Total</small></div></div><div class="public-dashboard-legend"><span><i></i> Fees collected <b>KES 84,500</b></span><span><i></i> Other income <b>KES 17,500</b></span></div></div>
                                </div>
                                <div class="public-dashboard-panel public-dashboard-activity"><div class="public-dashboard-panel-heading"><span><b>Recent activity</b><small>School operations at a glance</small></span><span class="public-dashboard-view">View all <i class="fa-solid fa-arrow-right"></i></span></div><div class="public-dashboard-activity-row"><span class="activity-fee"><i class="fa-solid fa-wallet"></i></span><span><b>Fee payment recorded</b><small>School fees · Recorded</small></span><strong>KES 4,000</strong><i class="fa-solid fa-circle-check activity-check"></i></div><div class="public-dashboard-activity-row"><span class="activity-student"><i class="fa-solid fa-user-plus"></i></span><span><b>Student record updated</b><small>Student records · Updated</small></span><strong>Updated</strong><i class="fa-solid fa-circle-check activity-check"></i></div></div>
                                <div class="public-dashboard-preview-caption"><i class="fa-solid fa-circle-info"></i> School fees · Student records · Daily operations</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="public-home-platform" aria-labelledby="platform-heading">
            <div class="public-home-section-heading">
                <div>
                    <p class="public-home-eyebrow">One connected workspace</p>
                    <h2 id="platform-heading">The tools behind a well-run school</h2>
                </div>
                <p>Clear workflows for the work that keeps each school day moving.</p>
            </div>
            <div class="public-home-features">
                <article class="public-home-feature">
                    <span class="public-home-feature-icon fees"><i class="fa-solid fa-coins" aria-hidden="true"></i></span>
                    <h3>Fees</h3>
                    <p>Collections, balances, and receipt workflows.</p>
                </article>
                <article class="public-home-feature">
                    <span class="public-home-feature-icon students"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
                    <h3>Students</h3>
                    <p>Enrollment, student records, and class lists.</p>
                </article>
                <article class="public-home-feature">
                    <span class="public-home-feature-icon reports"><i class="fa-solid fa-chart-column" aria-hidden="true"></i></span>
                    <h3>Reports</h3>
                    <p>Useful summaries to support everyday decisions.</p>
                </article>
                <article class="public-home-feature">
                    <span class="public-home-feature-icon administration"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                    <h3>Administration</h3>
                    <p>Secure tools for authorized school users.</p>
                </article>
            </div>
        </section>
    </main>

    <footer class="public-home-footer">
        <span><?= h(SCHOOL_NAME) ?></span>
        <span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secure · Reliable · For a brighter future</span>
    </footer>
</body>
</html>
