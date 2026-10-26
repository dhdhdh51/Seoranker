package app.flatmates.android

import java.net.URI
import java.net.URISyntaxException

/**
 * Decides where a URL may open. Only HTTPS pages on the configured production host load inside
 * the WebView; ordinary web links and mail/phone links go to the system; everything else
 * (javascript:, intent:, file:, content:, data:, malformed URLs) is refused.
 *
 * Pure Kotlin/JVM so it is covered by local unit tests.
 */
class UrlPolicy(allowedHost: String) {
    private val host = allowedHost.lowercase()

    sealed class Decision {
        /** Load inside the app. [url] is the URL to load (http:// on our host is upgraded to https://). */
        data class Internal(val url: String) : Decision()

        /** Hand to another app (browser, mail, dialer). */
        data class External(val url: String) : Decision()

        /** Never open. */
        object Blocked : Decision()
    }

    fun decide(raw: String?): Decision {
        if (raw.isNullOrBlank() || raw.length > 8192) return Decision.Blocked
        val uri = try {
            URI(raw.trim())
        } catch (e: URISyntaxException) {
            return Decision.Blocked
        }
        val scheme = uri.scheme?.lowercase() ?: return Decision.Blocked
        return when (scheme) {
            "https", "http" -> {
                val h = uri.host?.lowercase() ?: return Decision.Blocked
                if (uri.rawUserInfo != null) return Decision.Blocked // https://trusted@evil.example
                if (h == host) {
                    val portOk = uri.port == -1 || (scheme == "https" && uri.port == 443) || (scheme == "http" && uri.port == 80)
                    if (!portOk) return Decision.Blocked
                    if (scheme == "https") {
                        Decision.Internal(raw.trim())
                    } else {
                        // Upgrade plain-HTTP links to our own host; cleartext is never loaded.
                        val rebuilt = buildString {
                            append("https://").append(h)
                            append(uri.rawPath?.ifEmpty { "/" } ?: "/")
                            uri.rawQuery?.let { append('?').append(it) }
                            uri.rawFragment?.let { append('#').append(it) }
                        }
                        Decision.Internal(rebuilt)
                    }
                } else {
                    Decision.External(raw.trim())
                }
            }
            "mailto", "tel", "sms", "geo" -> Decision.External(raw.trim())
            else -> Decision.Blocked
        }
    }

    /** True only for HTTPS URLs on the trusted host — used before attaching session cookies. */
    fun isTrustedHttps(raw: String?): Boolean {
        val d = decide(raw)
        return d is Decision.Internal && raw!!.trim().startsWith("https://", ignoreCase = true)
    }
}
