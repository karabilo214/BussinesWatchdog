#!/bin/sh
set -eu

bucket="${S3_BUCKET:-business-watchdog-local}"
region="${AWS_DEFAULT_REGION:-eu-central-1}"

awslocal s3 mb "s3://${bucket}" --region "${region}" 2>/dev/null || true
