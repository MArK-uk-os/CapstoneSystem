<?php
class DisconnectionService
{
    public function __construct(private PDO $pdo) {}

    public function eligibleInvoice(int $serviceId): array|false
    {
        $query=$this->pdo->prepare("SELECT b.id,b.invoice_number,DATEDIFF(CURDATE(),b.due_date) overdue_days,
            b.amount-COALESCE(p.paid,0) outstanding
            FROM billing b LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id
            WHERE b.customer_service_id=? AND b.amount>COALESCE(p.paid,0)
            AND DATEDIFF(CURDATE(),b.due_date)>=(SELECT disconnection_days FROM automation_settings WHERE id=1)
            ORDER BY b.due_date,b.id LIMIT 1");
        $query->execute([$serviceId]);
        return $query->fetch();
    }

    public function disconnect(int $serviceId, string $charge, string $worker, string $remarks, int $staff): void
    {
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$charge) || (float)$charge>99999999.99 || !trim($worker) || strlen($worker)>100 || strlen($remarks)>1000) throw new RuntimeException('Enter a valid charge, field worker name, and remarks (maximum 1,000 characters).');
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT status FROM customer_services WHERE id=? FOR UPDATE');$query->execute([$serviceId]);
            if ($query->fetchColumn()!=='active') throw new RuntimeException('Only an active service can be disconnected.');
            $invoice=$this->eligibleInvoice($serviceId);
            if (!$invoice) throw new RuntimeException('This service has no unpaid invoice past the disconnection threshold.');
            $this->pdo->prepare('INSERT INTO service_disconnections(customer_service_id,overdue_billing_id,charge,field_worker,remarks,recorded_by) VALUES (?,?,?,?,?,?)')->execute([$serviceId,$invoice['id'],$charge,trim($worker),trim($remarks),$staff]);
            $this->pdo->prepare("UPDATE customer_services SET status='disconnected' WHERE id=?")->execute([$serviceId]);
            $this->pdo->commit();
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e; }
    }

    public function reconnect(int $serviceId, int $staff): void
    {
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT status FROM customer_services WHERE id=? FOR UPDATE');$query->execute([$serviceId]);
            if($query->fetchColumn()!=='disconnected')throw new RuntimeException('This service is not disconnected.');
            $this->pdo->prepare('UPDATE service_disconnections SET reconnected_at=NOW(),reconnected_by=? WHERE customer_service_id=? AND reconnected_at IS NULL')->execute([$staff,$serviceId]);
            $this->pdo->prepare("UPDATE customer_services SET status='active' WHERE id=?")->execute([$serviceId]);
            $this->pdo->commit();
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e; }
    }

    // Caller must hold the service row lock in the invoice creation transaction.
    public function applyCharges(int $serviceId, int $invoiceId): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Charges require an invoice transaction.');
        $query=$this->pdo->prepare('SELECT id,charge FROM service_disconnections WHERE customer_service_id=? AND billed_invoice_id IS NULL FOR UPDATE');$query->execute([$serviceId]);
        $charges=$query->fetchAll();$total=0;
        foreach($charges as $charge)$total+=(int)round((float)$charge['charge']*100);
        if (!$charges) return;
        $this->pdo->prepare('UPDATE billing SET amount=amount+?,disconnection_charge=disconnection_charge+? WHERE id=? AND customer_service_id=?')->execute([$total/100,$total/100,$invoiceId,$serviceId]);
        $this->pdo->prepare('UPDATE service_disconnections SET billed_invoice_id=? WHERE customer_service_id=? AND billed_invoice_id IS NULL')->execute([$invoiceId,$serviceId]);
    }
}
