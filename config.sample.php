<?php

// LLM Provider Configuration
// Options: 'anthropic', 'google'
$llmProvider = 'anthropic';

// API Key for the selected provider
$llmApiKey = '';

// Model Selection
// Leave empty to use defaults:
// - Anthropic: 'claude-haiku-4-5'
// - Google: 'gemini-3-flash-preview'
$llmModel = '';

$db1Config = [
  'label' => 'Preprod',
  'host' => '',
  'username' => '',
  'password' => '',
  'database' => '',
];
$db2Config = [
  'label' => 'Production',
  'host' => '',
  'username' => '',
  'password' => '',
  'database' => '',
];
$storageDatabase = [
  'host' => '',
  'username' => '',
  'password' => '',
  'database' => ''
];

