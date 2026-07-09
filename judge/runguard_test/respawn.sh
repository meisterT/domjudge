#!/usr/bin/bash

# Leave a process behind that keeps forking new children, and that is in a
# session of its own so that it cannot be signalled through our process group.
# Killing the cgroup has to cope with the children appearing while it runs.
# The loop is bounded so that a failure to kill it does not leave a process
# forking on the test machine indefinitely.
setsid bash -c 'for _ in $(seq 1 200); do sleep 1 & sleep 0.02; done' \
	< /dev/null > /dev/null 2>&1 &

echo "spawned $!"
