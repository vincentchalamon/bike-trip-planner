<?php

declare(strict_types=1);

namespace App\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The VM's SSH, firewall and backup invariants (ansible/README.md, ADR-062 addendum).
 */
final class HostHardeningTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../../ansible';

    #[Test]
    public function sshdOnlyAcceptsKeysFromTheKnownUsers(): void
    {
        $tasks = $this->tasks('hardening');
        $dropin = $this->yaml('roles/hardening/defaults/main.yml')['hardening_sshd_dropin'] ?? null;
        self::assertIsString($dropin);
        // sshd keeps the first value it reads: a later 50-cloud-init.conf must not win.
        self::assertMatchesRegularExpression('#^/etc/ssh/sshd_config\.d/00-[^/]+\.conf$#', $dropin);

        $directives = [];
        foreach ($this->lines('roles/hardening/templates/sshd-hardening.conf.j2') as $line) {
            [$key, $value] = explode(' ', $line, 2);
            $directives[$key] = $value;
        }

        self::assertSame('no', $directives['PasswordAuthentication'] ?? null);
        self::assertSame('no', $directives['KbdInteractiveAuthentication'] ?? null);
        self::assertSame('no', $directives['PermitRootLogin'] ?? null);
        self::assertSame('publickey', $directives['AuthenticationMethods'] ?? null);
        self::assertSame("{{ hardening_ssh_allow_users | join(' ') }}", $directives['AllowUsers'] ?? null);

        $validated = false;
        foreach ($tasks as $task) {
            foreach ($task['block'] ?? [] as $step) {
                if ('/usr/sbin/sshd -t' === ($step['ansible.builtin.command'] ?? null)) {
                    $validated = true;
                }
            }
        }
        self::assertTrue($validated, 'the sshd drop-in is not checked with sshd -t');
    }

    #[Test]
    public function theHostFirewallLeavesDockersTablesAlone(): void
    {
        $rules = $this->lines('roles/hardening/templates/firewall.nft.j2');
        $unit = $this->lines('roles/hardening/templates/btp-firewall.service.j2');

        foreach ([...$rules, ...$unit] as $line) {
            // `flush ruleset` would also wipe Docker's tables and cut the containers off.
            self::assertStringNotContainsString('flush ruleset', $line);
        }
        self::assertContains('table inet btp_host', $rules);
        self::assertContains('delete table inet btp_host', $rules);
        self::assertContains('type filter hook input priority filter; policy drop;', $rules);
        self::assertNotContains('type filter hook forward priority filter; policy drop;', $rules);
        self::assertContains('ExecStop=/usr/sbin/nft delete table inet btp_host', $unit);
    }

    #[Test]
    public function aKeyDroppedFromAnAuthorizedKeysListIsRevoked(): void
    {
        $users = [];
        foreach ($this->tasks('common') as $task) {
            $key = $task['ansible.posix.authorized_key'] ?? null;
            if (\is_array($key)) {
                $users[(string) $key['user']] = $key;
            }
        }

        foreach (['{{ deploy_user }}', '{{ preview_deploy_user }}'] as $user) {
            self::assertArrayHasKey($user, $users);
            self::assertTrue($users[$user]['exclusive'] ?? false, $user.' authorized_keys is not exclusive');
            self::assertStringContainsString("| join('\n')", (string) $users[$user]['key']);
        }
    }

    #[Test]
    public function aFailedBackupIsReported(): void
    {
        self::assertContains(
            'OnFailure=btp-backup-failed@%n.service',
            $this->lines('roles/backup/templates/btp-backup.service.j2'),
        );

        $installed = [];
        foreach ($this->tasks('backup') as $task) {
            $installed[] = $task['ansible.builtin.template']['dest'] ?? null;
        }
        self::assertContains('/etc/systemd/system/btp-backup-failed@.service', $installed);
    }

    #[Test]
    public function inLifecycleModeTheBackupNeverDeletes(): void
    {
        $script = file_get_contents(self::ROOT.'/roles/backup/templates/btp-backup.sh.j2');
        self::assertIsString($script);

        $guard = strpos($script, 'if [ "$RETENTION_MODE" = "script" ]; then');
        self::assertIsInt($guard, 'the prune is not guarded by RETENTION_MODE');
        self::assertSame(1, preg_match('/^\s*fi$/m', $script, $fi, \PREG_OFFSET_CAPTURE, $guard));
        $end = $fi[0][1];

        preg_match_all('/^\s*prune "/m', $script, $calls, \PREG_OFFSET_CAPTURE);
        self::assertNotEmpty($calls[0]);
        foreach ($calls[0] as [, $offset]) {
            self::assertGreaterThan($guard, $offset, 'a prune runs outside the RETENTION_MODE guard');
            self::assertLessThan($end, $offset, 'a prune runs outside the RETENTION_MODE guard');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(string $role): array
    {
        /** @var list<array<string, mixed>> $tasks */
        $tasks = $this->yaml('roles/'.$role.'/tasks/main.yml');

        return $tasks;
    }

    /**
     * @return array<mixed>
     */
    private function yaml(string $path): array
    {
        $parsed = Yaml::parseFile(self::ROOT.'/'.$path);
        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * Non-empty, non-comment lines, trimmed.
     *
     * @return list<string>
     */
    private function lines(string $path): array
    {
        $lines = file(self::ROOT.'/'.$path, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return array_values(array_filter(
            array_map(trim(...), $lines),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#') && !str_starts_with($line, '{%'),
        ));
    }
}
