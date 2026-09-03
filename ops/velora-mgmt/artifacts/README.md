# Ephemeral run artifacts
#
Live workflow run outputs (inspect/plan/verify JSON, probe metadata) are NOT committed here.
They are emitted as GitHub Actions run artifacts (retention 14d). This directory exists so jobs
have a local scratch location; it must stay empty in git.
