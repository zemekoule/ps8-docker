#!/bin/bash

containers=$(docker container ls -q)

if [ -n "$containers" ]; then
    docker container stop $containers
else
    echo "No container running."
fi
