<?php
require_once '../../config/db.php';

$result = mysqli_query($conn, "
    SELECT b.*, u.name 
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    WHERE b.status = 'pending'
    ORDER BY b.created_at DESC
");

while ($row = mysqli_fetch_assoc($result)) {
    echo "<div style='padding:10px;border-bottom:1px solid #ccc'>";
    echo "<strong>{$row['name']}</strong><br>";
    echo "<i data-lucide="map-pin" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> {$row['pickup_location']} → <i data-lucide="flag" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> {$row['dropoff_location']}<br>";
    echo "<small>Status: {$row['status']}</small>";
    echo "</div>";
}
<script src="../assets/offline-icons.js"></script>
