<?php

namespace Mycache;

class Test {

    private $amount;

    public function __construct($amount) {
        $this->amount = $amount;
    }

    public function getAmount() {
        return $this->amount;
    }

    public function negate() {
        return new Test(-1 * $this->amount);
    }

    // ...
}
