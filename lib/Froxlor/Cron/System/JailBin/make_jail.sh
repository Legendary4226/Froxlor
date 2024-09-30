#!/bin/bash

CHROOT=$1
if [[ "$DIR" = /* ]]; then
	CHROOT=$1
else
	CHROOT="$(pwd)/$1"
fi

USERNAME=$2

# Setup directory layout
mkdir "$CHROOT"
mkdir -p "$CHROOT"/{dev,etc,home,tmp,proc,root,var}

# Setup device
mknod "$CHROOT"/dev/null c 1 3
mknod "$CHROOT"/dev/zero c 1 5
mknod "$CHROOT"/dev/tty  c 5 0
mknod "$CHROOT"/dev/random c 1 8
mknod "$CHROOT"/dev/urandom c 1 9
chmod 0666 "$CHROOT"/dev/{null,tty,zero}
chown root.tty "$CHROOT"/dev/tty

# Setup user
ETC_FOLDER="/etc"# "/var/lib/extrausers"
touch "$CHROOT"/etc/{shadow,passwd,group,nsswitch.conf}
awk "/^$USERNAME/" "$ETC_FOLDER"/passwd | sed "s|/var/customers/webs|$CHROOT/./home|g" | sed "s|/bin/false|/bin/bash|g" >> "$CHROOT"/etc/passwd
awk "/^$USERNAME/" "$ETC_FOLDER"/group >> "$CHROOT"/etc/group
awk "/^$USERNAME/" "$ETC_FOLDER"/shadow >> "$CHROOT"/etc/shadow
cat > "$CHROOT"/etc/nsswitch.conf <<EOF
passwd: files ${CHROOT}/etc/passwd
group: files ${CHROOT}/etc/group
shadow: files ${CHROOT}/etc/shadow
EOF
mkdir "$CHROOT"/home/"$USERNAME"
if [ -d /etc/skel ]; then
	cp /etc/skel/{.bashrc,.profile,.bash_logout} "$CHROOT"/home/"$USERNAME"
fi
cp --parents /etc/pam.conf "$CHROOT"
cp --parents /etc/security/* "$CHROOT"
cp --parents /etc/pam.d/{ssh,other} "$CHROOT"

# Setup default commands
bash ./copy_binaries.sh "$CHROOT" base_commands

# Go inside the chroot to setup permissions of the user
touch "$CHROOT"/tmp.sh
cat > "$CHROOT"/tmp.sh <<EOF
chown -R "${USERNAME}":"${USERNAME}" /home/"${USERNAME}"
chmod -R 755 /home/"${USERNAME}"
EOF
chroot "$CHROOT" /bin/bash /tmp.sh
rm -rf "$CHROOT"/tmp.sh
