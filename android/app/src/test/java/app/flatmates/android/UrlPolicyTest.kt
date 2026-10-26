package app.flatmates.android

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class UrlPolicyTest {
    private val p = UrlPolicy("flatmates.noidarankers.in")

    private fun internal(u: String) = (p.decide(u) as? UrlPolicy.Decision.Internal)?.url

    @Test
    fun trustedHttpsPagesLoadInside() {
        assertEquals("https://flatmates.noidarankers.in/chat/5?x=1#m", internal("https://flatmates.noidarankers.in/chat/5?x=1#m"))
        assertEquals("https://FLATMATES.noidarankers.in/", internal("https://FLATMATES.noidarankers.in/"))
        assertTrue(p.isTrustedHttps("https://flatmates.noidarankers.in/account/export"))
    }

    @Test
    fun plainHttpOnOurHostIsUpgradedNeverLoaded() {
        assertEquals("https://flatmates.noidarankers.in/rooms?q=a", internal("http://flatmates.noidarankers.in/rooms?q=a"))
        assertEquals("https://flatmates.noidarankers.in/", internal("http://flatmates.noidarankers.in"))
        assertFalse(p.isTrustedHttps("http://flatmates.noidarankers.in/"))
    }

    @Test
    fun otherSitesOpenExternally() {
        assertTrue(p.decide("https://github.com/o/r/releases") is UrlPolicy.Decision.External)
        assertTrue(p.decide("https://noidarankers.in/") is UrlPolicy.Decision.External)
        assertTrue(p.decide("https://flatmates.noidarankers.in.evil.example/") is UrlPolicy.Decision.External)
        assertTrue(p.decide("https://evilflatmates.noidarankers.in/") is UrlPolicy.Decision.External)
        assertTrue(p.decide("mailto:support@example.com") is UrlPolicy.Decision.External)
        assertTrue(p.decide("tel:112") is UrlPolicy.Decision.External)
        assertFalse(p.isTrustedHttps("https://github.com/x.apk"))
    }

    @Test
    fun dangerousOrMalformedUrlsAreBlocked() {
        for (u in listOf(
            "javascript:alert(1)", "intent://scan/#Intent;scheme=zxing;end", "file:///data/data/x", "content://x/y",
            "data:text/html,<b>x</b>", "https://flatmates.noidarankers.in@evil.example/", "https://user:pw@flatmates.noidarankers.in/",
            "https://flatmates.noidarankers.in:8443/", "http://flatmates.noidarankers.in:8080/", "", "   ", "https://",
            "ht tp://bad url", "chrome://settings",
        )) {
            assertEquals("should block: $u", UrlPolicy.Decision.Blocked, p.decide(u))
        }
        assertEquals(UrlPolicy.Decision.Blocked, p.decide(null))
    }
}
