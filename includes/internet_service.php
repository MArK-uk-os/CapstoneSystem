<?php
// The service identifies internet access; the subscription's plan determines its price.
function internetServiceId(PDO $pdo): int
{
    $query = $pdo->prepare('SELECT id FROM services WHERE service_name=? ORDER BY id LIMIT 1');
    $query->execute(['Internet']);
    $id = $query->fetchColumn();
    if (!$id) {
        $pdo->prepare('INSERT INTO services(service_name,monthly_fee,description,status) VALUES (?,0,?,?)')->execute(['Internet', 'Internet access billed at the subscribed internet plan rate.', 'active']);
        $id = $pdo->lastInsertId();
    }
    return (int) $id;
}
