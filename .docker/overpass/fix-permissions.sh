#!/bin/sh
# Fix /db directory permissions so fcgiwrap (nginx user) can access the dispatcher socket in /db/db/
chmod 755 /db
