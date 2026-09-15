package bd.ac.primeuniversity.studentportal.ui.results

import android.util.TypedValue
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.annotation.ColorRes
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.ResultEntry
import bd.ac.primeuniversity.studentportal.data.model.SemesterResult
import bd.ac.primeuniversity.studentportal.databinding.ItemResultCourseBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemResultSetBinding
import java.util.Locale

/**
 * One card per semester. Course rows are inflated straight into the card (a
 * semester has only a handful) so each card scrolls as a unit, matching the
 * table-per-semester layout of the web My Results page. Registered courses
 * whose result is not released yet are listed as "Not published yet" rows
 * with the course teacher, exactly like the web page.
 */
class ResultSetAdapter : ListAdapter<SemesterResult, ResultSetAdapter.Holder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
        val binding = ItemResultSetBinding.inflate(
            LayoutInflater.from(parent.context), parent, false
        )
        return Holder(binding)
    }

    override fun onBindViewHolder(holder: Holder, position: Int) =
        holder.bind(getItem(position))

    inner class Holder(private val binding: ItemResultSetBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(result: SemesterResult) {
            val ctx = binding.root.context
            binding.resultTitle.text = result.title

            val count = result.entries.size
            val pending = result.pendingCount
            binding.resultMeta.text = listOfNotNull(
                result.semester?.takeIf { it.isNotBlank() },
                ctx.resources.getQuantityString(R.plurals.results_courses, count, count),
                if (pending > 0) ctx.getString(R.string.results_pending_count, pending) else null,
                result.cgpa?.let {
                    ctx.getString(R.string.results_cgpa_value, String.format(Locale.US, "%.2f", it))
                },
            ).joinToString(" · ")

            // Course rows (published first, then not published yet – already ordered by the API)
            val inflater = LayoutInflater.from(ctx)
            binding.courseRows.removeAllViews()
            result.entries.forEachIndexed { index, entry ->
                val row = ItemResultCourseBinding.inflate(inflater, binding.courseRows, false)
                bindCourse(row, entry)
                row.rowDivider.visibility =
                    if (index < result.entries.lastIndex) View.VISIBLE else View.GONE
                binding.courseRows.addView(row.root)
            }

            // Footer status: everything published, or how many results are still awaited.
            if (pending > 0) {
                binding.footerIcon.imageTintList =
                    ContextCompat.getColorStateList(ctx, R.color.grade_pending_fg)
                binding.footerLabel.text = ctx.getString(R.string.results_pending_count, pending)
                binding.footerLabel.setTextColor(color(ctx, R.color.grade_pending_fg))
            } else {
                binding.footerIcon.imageTintList =
                    ContextCompat.getColorStateList(ctx, R.color.success)
                binding.footerLabel.setText(R.string.results_published)
                binding.footerLabel.setTextColor(color(ctx, R.color.text_secondary))
            }

            // GPA footer – "Pending" while nothing is published in the term, withheld
            // (with the reason) when the semester has an F / Incom, like the web page.
            val gpa = result.gpa
            when {
                result.publishedCount == 0 -> {
                    binding.gpaValue.setText(R.string.results_gpa_pending)
                    binding.gpaValue.setTextColor(color(ctx, R.color.grade_pending_fg))
                }
                result.gpaIncomplete -> {
                    binding.gpaValue.text = result.gpaStatus?.takeIf { it.isNotBlank() }
                        ?: ctx.getString(R.string.results_incomplete)
                    binding.gpaValue.setTextColor(color(ctx, R.color.grade_f_fg))
                }
                gpa != null -> {
                    binding.gpaValue.text = String.format(Locale.US, "%.2f", gpa)
                    binding.gpaValue.setTextColor(color(ctx, gpColor(gpa)))
                }
                else -> {
                    binding.gpaValue.setText(R.string.dash)
                    binding.gpaValue.setTextColor(color(ctx, R.color.text_secondary))
                }
            }
        }

        private fun bindCourse(row: ItemResultCourseBinding, entry: ResultEntry) {
            val ctx = row.root.context

            val code = entry.courseCode?.takeIf { it.isNotBlank() }
            row.courseCode.text = code
            row.courseCode.visibility = if (code == null) View.GONE else View.VISIBLE
            row.courseTitle.text =
                entry.courseTitle?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.dash)

            row.credit.text = entry.credit?.let { String.format(Locale.US, "%.2f", it) }
                ?: ctx.getString(R.string.dash)

            if (entry.isPending) {
                bindPendingCourse(row, entry)
                return
            }

            row.root.setBackgroundColor(color(ctx, android.R.color.transparent))
            row.grade.setTextSize(TypedValue.COMPLEX_UNIT_SP, 13f)

            val grade = entry.letterGrade?.trim().orEmpty()
            val (bg, fg) = gradeColors(grade)
            row.grade.text = when {
                entry.isIncomplete -> ctx.getString(R.string.results_incomplete)
                grade.isBlank() -> ctx.getString(R.string.dash)
                else -> grade
            }
            row.grade.setTextColor(color(ctx, fg))
            row.grade.backgroundTintList = ContextCompat.getColorStateList(ctx, bg)

            val gp = entry.gradePoint
            when {
                entry.isIncomplete -> {
                    row.gradePoint.setText(R.string.results_incomplete)
                    row.gradePoint.setTextColor(color(ctx, R.color.grade_other_fg))
                }
                gp != null -> {
                    row.gradePoint.text = String.format(Locale.US, "%.2f", gp)
                    row.gradePoint.setTextColor(color(ctx, gpColor(gp)))
                }
                else -> {
                    row.gradePoint.setText(R.string.dash)
                    row.gradePoint.setTextColor(color(ctx, R.color.text_secondary))
                }
            }

            // Secondary line: the "not counted" hint for an F / Incom, else the remarks (as on the web page).
            val note: Pair<String, Int>? = when {
                entry.isIncomplete || entry.isFail ->
                    ctx.getString(R.string.results_not_counted) to R.color.grade_f_fg
                else -> entry.remarks?.takeIf { it.isNotBlank() }?.let { it to R.color.text_secondary }
            }
            if (note != null) {
                row.courseNote.text = note.first
                row.courseNote.setTextColor(color(ctx, note.second))
                row.courseNote.visibility = View.VISIBLE
            } else {
                row.courseNote.visibility = View.GONE
            }
        }

        /** Registered course of a completed exam whose result is not released yet. */
        private fun bindPendingCourse(row: ItemResultCourseBinding, entry: ResultEntry) {
            val ctx = row.root.context
            row.root.setBackgroundColor(color(ctx, R.color.pending_row_bg))

            row.grade.text = ctx.getString(R.string.results_pending_badge)
            row.grade.setTextSize(TypedValue.COMPLEX_UNIT_SP, 10f)
            row.grade.setTextColor(color(ctx, R.color.grade_pending_fg))
            row.grade.backgroundTintList =
                ContextCompat.getColorStateList(ctx, R.color.grade_pending_bg)

            row.gradePoint.setText(R.string.dash)
            row.gradePoint.setTextColor(color(ctx, R.color.text_secondary))

            val teachers = entry.teachers?.takeIf { it.isNotBlank() }
            row.courseNote.text = if (teachers != null) {
                ctx.getString(R.string.results_pending_teacher, teachers)
            } else {
                ctx.getString(R.string.results_pending_no_teacher)
            }
            row.courseNote.setTextColor(color(ctx, R.color.grade_pending_fg))
            row.courseNote.visibility = View.VISIBLE
        }
    }

    companion object {
        private fun color(ctx: android.content.Context, @ColorRes res: Int) =
            ContextCompat.getColor(ctx, res)

        /** Same badge palette as the web result page. Returns (background, text). */
        private fun gradeColors(grade: String): Pair<Int, Int> =
            when (grade.uppercase(Locale.US)) {
                "A+", "A" -> R.color.grade_a_plus_bg to R.color.grade_a_plus_fg
                "A-", "B+" -> R.color.grade_a_bg to R.color.grade_a_fg
                "B", "B-" -> R.color.grade_b_bg to R.color.grade_b_fg
                "C+", "C" -> R.color.grade_c_bg to R.color.grade_c_fg
                "D" -> R.color.grade_d_bg to R.color.grade_d_fg
                "F" -> R.color.grade_f_bg to R.color.grade_f_fg
                else -> R.color.grade_other_bg to R.color.grade_other_fg
            }

        /** Grade-point colour bands, identical to pub_gp_color() on the web. */
        @ColorRes
        private fun gpColor(gp: Double): Int = when {
            gp >= 3.75 -> R.color.gp_excellent
            gp >= 3.00 -> R.color.gp_good
            gp >= 2.50 -> R.color.gp_fair
            gp >= 2.00 -> R.color.gp_low
            else -> R.color.gp_fail
        }

        private val DIFF = object : DiffUtil.ItemCallback<SemesterResult>() {
            override fun areItemsTheSame(a: SemesterResult, b: SemesterResult) = a.id == b.id
            override fun areContentsTheSame(a: SemesterResult, b: SemesterResult) = a == b
        }
    }
}
