<?php

namespace App\Queue;

/**
 * POS-related emails: always persist to the database jobs table so
 * KOT/order/payment requests stay fast even when QUEUE_CONNECTION=sync.
 */
trait UsesDatabaseQueue
{
    public $connection = 'database';
}
