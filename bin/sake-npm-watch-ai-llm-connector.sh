#!/bin/bash

source ~/.nvm/nvm.sh
nvm use node

echo '------------------------------'
echo ' run build'
echo '------------------------------'
vendor/bin/webpack install vendor/sunnysideup/ai-llm-connector/client
vendor/bin/webpack build vendor/sunnysideup/ai-llm-connector/client --no-jquery
echo '------------------------------'
