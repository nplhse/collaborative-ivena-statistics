<?php

$ruleset = new TwigCsFixer\Ruleset\Ruleset();

// Add default fixes via standard
$ruleset->addStandard(new TwigCsFixer\Standard\TwigCsFixer());

$config = new TwigCsFixer\Config\Config();
$config->setRuleset($ruleset);

return $config;
