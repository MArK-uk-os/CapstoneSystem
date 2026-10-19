<?php
// One-time service offerings: charged via a standalone invoice (billing.customer_service_id = NULL).
// They are NOT subscriptions and must never be wired to the recurring billing engine.
const ONE_TIME_SERVICES = ['CCTV Installation'];
