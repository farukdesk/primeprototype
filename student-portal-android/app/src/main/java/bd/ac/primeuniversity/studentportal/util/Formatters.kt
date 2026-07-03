package bd.ac.primeuniversity.studentportal.util

import java.text.NumberFormat
import java.util.Locale

/** Formatting helpers shared across screens. */
object Formatters {

    private val amountFormat: NumberFormat = NumberFormat.getNumberInstance(Locale.US).apply {
        minimumFractionDigits = 2
        maximumFractionDigits = 2
    }

    private val wholeFormat: NumberFormat = NumberFormat.getNumberInstance(Locale.US).apply {
        maximumFractionDigits = 0
    }

    const val TAKA = "৳"

    /** e.g. 12345.6 -> "৳12,345.60" */
    fun money(value: Double): String = TAKA + amountFormat.format(value)

    /** e.g. 12345.6 -> "৳12,346" (whole taka, for compact stats). */
    fun moneyWhole(value: Double): String = TAKA + wholeFormat.format(value)
}
