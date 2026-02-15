<?php
namespace APM;

interface Pricing_Strategy_Interface {
    public function get_key() : string;

    public function supports( Pricing_Context $context ) : bool;

    public function decide( Pricing_Context $context ) : ?Pricing_Decision;
}
