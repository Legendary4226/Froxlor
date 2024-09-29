#!/bin/bash

CHROOT=$1
COMMAND=$2

function copy_binary()
{
    for i in $(ldd "$*"|grep -v dynamic|cut -d " " -f 3|sed 's/://'|sort|uniq)
	do
		cp --parents "$i" "$CHROOT"
	done

    # ARCH amd64
    if [ -f /lib64/ld-linux-x86-64.so.2 ]; then
       cp --parents /lib64/ld-linux-x86-64.so.2 "$CHROOT"
    fi

    # ARCH i386
    if [ -f  /lib/ld-linux.so.2 ]; then
       cp --parents /lib/ld-linux.so.2 "$CHROOT"
    fi
}

if [ "$COMMAND" = "base_commands" ]; then
	baseBins=(
		"/bin/bash"
		"ls"
		"cp"
		"rm"
		"cat"
		"mkdir"
		"ln"
		"grep"
		"cut"
		"sed"
		"vim"
		"nano"
		"head"
		"tail"
		"which"
		"id"
		"find"
		"xargs"
		"clear"
		"touch"
		"whoami"
		"chmod"
		"chown"
	)
	for binary in "${baseBins[@]}"
	do
		binaryPath=$(which "$binary")
		copy_binary "$binaryPath"
		cp --parents "$binaryPath" "$CHROOT"
	done
else
	binaryPath=$(which "$COMMAND")
	copy_binary "$binaryPath"
	cp --parents "$binaryPath" "$CHROOT"
fi
